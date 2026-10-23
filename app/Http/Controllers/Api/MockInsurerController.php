<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MockInsurerController extends Controller
{
    /**
     * POST /api/mock-insurer/process
     * Internal endpoint that simulates the external insurer's behavior.
     * Configurable via the 'scenario' field.
     */
    public function process(Request $request): JsonResponse
    {
        $scenario = $request->input('scenario', 'SUCCESS');
        $policyRequestId = $request->input('policy_request_id');
        $uuid = $request->input('uuid');

        switch (strtoupper($scenario)) {
            case 'SUCCESS':
                return response()->json([
                    'status' => 'SUCCESS',
                    'policy_number' => 'POL-' . strtoupper(Str::random(8)),
                    'provider_reference' => 'REF-' . strtoupper(Str::random(10)),
                    'message' => 'Policy issued successfully.',
                ]);

            case 'TEMPORARY_FAILURE':
                return response()->json([
                    'status' => 'FAILURE',
                    'error' => 'Temporary service unavailability. Please retry.',
                    'retriable' => true,
                ], 503);

            case 'TIMEOUT_AFTER_ISSUE':
                // Simulate: the insurer actually creates the policy internally
                // but the HTTP response times out.
                // Store the "secret" issued policy for reconciliation lookup.
                $policyNumber = 'POL-' . strtoupper(Str::random(8));
                $providerRef = 'REF-' . strtoupper(Str::random(10));

                // Store in cache/session for the status lookup endpoint
                cache()->put(
                    "mock_insurer_issued_{$policyRequestId}",
                    [
                        'policy_number' => $policyNumber,
                        'provider_reference' => $providerRef,
                        'status' => 'ISSUED',
                    ],
                    now()->addHours(24)
                );

                // Simulate timeout by sleeping (the HTTP client timeout will catch this)
                sleep(15);

                // This response will likely never be received due to timeout
                return response()->json([
                    'status' => 'SUCCESS',
                    'policy_number' => $policyNumber,
                    'provider_reference' => $providerRef,
                ]);

            case 'DELAYED_CALLBACK':
                $providerRef = 'REF-' . strtoupper(Str::random(10));

                // Store callback data for later delivery
                cache()->put(
                    "mock_insurer_pending_callback_{$policyRequestId}",
                    [
                        'event_id' => 'EVT-' . strtoupper(Str::random(10)),
                        'policy_request_id' => $policyRequestId,
                        'event_type' => 'POLICY_ISSUED',
                        'policy_number' => 'POL-' . strtoupper(Str::random(8)),
                        'provider_reference' => $providerRef,
                    ],
                    now()->addHours(24)
                );

                return response()->json([
                    'status' => 'PENDING',
                    'provider_reference' => $providerRef,
                    'message' => 'Request accepted. Callback will be sent later.',
                ]);

            case 'DUPLICATE_CALLBACK':
                $providerRef = 'REF-' . strtoupper(Str::random(10));
                $policyNumber = 'POL-' . strtoupper(Str::random(8));

                // Store callback data that will be sent multiple times
                $callbackData = [
                    'event_id' => 'EVT-' . strtoupper(Str::random(10)),
                    'policy_request_id' => $policyRequestId,
                    'event_type' => 'POLICY_ISSUED',
                    'policy_number' => $policyNumber,
                    'provider_reference' => $providerRef,
                ];

                cache()->put(
                    "mock_insurer_duplicate_callback_{$policyRequestId}",
                    $callbackData,
                    now()->addHours(24)
                );

                return response()->json([
                    'status' => 'PENDING',
                    'provider_reference' => $providerRef,
                    'message' => 'Request accepted. Multiple callbacks will be sent (simulating duplicates).',
                ]);

            default:
                return response()->json([
                    'status' => 'FAILURE',
                    'error' => "Unknown scenario: {$scenario}",
                ], 400);
        }
    }

    /**
     * GET /api/mock-insurer/status/{policyRequestId}
     * Reconciliation endpoint to check if a policy was actually issued.
     */
    public function status(int $policyRequestId): JsonResponse
    {
        $cachedStatus = cache()->get("mock_insurer_issued_{$policyRequestId}");

        if ($cachedStatus) {
            return response()->json([
                'status' => 'found',
                'data' => $cachedStatus,
            ]);
        }

        // Check if there's a pending callback
        $pendingCallback = cache()->get("mock_insurer_pending_callback_{$policyRequestId}");
        if ($pendingCallback) {
            return response()->json([
                'status' => 'pending',
                'message' => 'Policy is still being processed.',
            ]);
        }

        return response()->json([
            'status' => 'not_found',
            'message' => 'No record found for this policy request.',
        ], 404);
    }

    /**
     * POST /api/mock-insurer/trigger-callback/{policyRequestId}
     * Development helper: manually trigger a pending callback.
     */
    public function triggerCallback(int $policyRequestId): JsonResponse
    {
        // Check for pending callback
        $callbackData = cache()->get("mock_insurer_pending_callback_{$policyRequestId}");

        if (!$callbackData) {
            // Check for duplicate callback
            $callbackData = cache()->get("mock_insurer_duplicate_callback_{$policyRequestId}");
        }

        if (!$callbackData) {
            return response()->json([
                'status' => 'error',
                'message' => 'No pending callback found for this policy request.',
            ], 404);
        }

        try {
            if (config('services.mock_insurer.use_http', false)) {
                $baseUrl = config('app.url', 'http://nginx');
                $response = \Illuminate\Support\Facades\Http::post(
                    "{$baseUrl}/api/mock-insurer/callback",
                    $callbackData
                );
                $result = ['first_callback' => $response->json()];

                $isDuplicate = cache()->has("mock_insurer_duplicate_callback_{$policyRequestId}");
                if ($isDuplicate) {
                    $response2 = \Illuminate\Support\Facades\Http::post(
                        "{$baseUrl}/api/mock-insurer/callback",
                        $callbackData
                    );
                    $result['second_callback'] = $response2->json();

                    $response3 = \Illuminate\Support\Facades\Http::post(
                        "{$baseUrl}/api/mock-insurer/callback",
                        $callbackData
                    );
                    $result['third_callback'] = $response3->json();
                }
            } else {
                $service = app(\App\Services\IssuanceService::class);
                $result = ['first_callback' => $service->processCallback($callbackData)];

                $isDuplicate = cache()->has("mock_insurer_duplicate_callback_{$policyRequestId}");
                if ($isDuplicate) {
                    $result['second_callback'] = $service->processCallback($callbackData);
                    $result['third_callback'] = $service->processCallback($callbackData);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Callback(s) triggered.',
                'results' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => "Failed to trigger callback: {$e->getMessage()}",
            ], 500);
        }
    }
}
