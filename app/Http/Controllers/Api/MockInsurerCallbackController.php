<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockInsurerCallbackController extends Controller
{
    public function __construct(
        private IssuanceService $issuanceService
    ) {}

    /**
     * POST /api/mock-insurer/callback
     * Receive a simulated provider event (callback).
     * Handles duplicate callbacks idempotently via unique event_id.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|string',
            'policy_request_id' => 'required|integer',
            'event_type' => 'required|string|in:POLICY_ISSUED,POLICY_FAILED',
            'provider_reference' => 'nullable|string',
            'policy_number' => 'nullable|string',
            'error' => 'nullable|string',
        ]);

        $result = $this->issuanceService->processCallback($request->all());

        $statusCode = match ($result['status']) {
            'processed' => 200,
            'duplicate' => 200,
            'acknowledged' => 200,
            'error' => 422,
            'ignored' => 200,
            default => 500,
        };

        return response()->json($result, $statusCode);
    }
}
