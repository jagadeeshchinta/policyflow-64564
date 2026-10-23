<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IssuanceService
{
    /**
     * Transition a policy request to a new status with validation and audit trail.
     *
     * @throws \RuntimeException If transition is invalid
     */
    public function transitionState(
        PolicyRequest $policyRequest,
        string $newStatus,
        string $reason = '',
        string $triggeredBy = 'system',
        array $metadata = []
    ): PolicyRequest {
        if (!$policyRequest->canTransitionTo($newStatus)) {
            throw new \RuntimeException(
                "Invalid state transition from '{$policyRequest->status}' to '{$newStatus}' " .
                "for policy request #{$policyRequest->id}."
            );
        }

        $oldStatus = $policyRequest->status;

        DB::transaction(function () use ($policyRequest, $newStatus, $oldStatus, $reason, $triggeredBy, $metadata) {
            $policyRequest->status = $newStatus;
            $policyRequest->save();

            PolicyStateHistory::create([
                'policy_request_id' => $policyRequest->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'reason' => $reason,
                'triggered_by' => $triggeredBy,
                'metadata' => $metadata,
            ]);
        });

        return $policyRequest->fresh();
    }

    /**
     * Submit a validated policy request to the mock insurer.
     * Handles the full submission flow including retry safety.
     */
    public function submitToInsurer(PolicyRequest $policyRequest, ?string $scenario = null): PolicyRequest
    {
        // If already ISSUED, return as-is (idempotent)
        if ($policyRequest->status === 'ISSUED') {
            return $policyRequest;
        }

        // Only VALIDATED or FAILED requests can be submitted
        if (!in_array($policyRequest->status, ['VALIDATED', 'FAILED'])) {
            throw new \RuntimeException(
                "Cannot submit policy request in status '{$policyRequest->status}'. " .
                "Must be VALIDATED or FAILED."
            );
        }

        return DB::transaction(function () use ($policyRequest, $scenario) {
            // Lock the row to prevent concurrent submissions
            $policyRequest = PolicyRequest::lockForUpdate()->find($policyRequest->id);

            // Double-check status after acquiring lock (another request might have progressed it)
            if ($policyRequest->status === 'ISSUED') {
                return $policyRequest;
            }

            if (!in_array($policyRequest->status, ['VALIDATED', 'FAILED'])) {
                throw new \RuntimeException(
                    "Cannot submit policy request in status '{$policyRequest->status}' after lock."
                );
            }

            // Transition to SUBMITTED
            $this->transitionState($policyRequest, 'SUBMITTED', 'Submitting to insurer', 'api');

            // Increment attempt count
            $policyRequest->increment('attempt_count');
            $attemptNumber = $policyRequest->attempt_count;

            // Prepare request payload
            $requestPayload = [
                'policy_request_id' => $policyRequest->id,
                'uuid' => $policyRequest->uuid,
                'customer_name' => $policyRequest->customer_name,
                'date_of_birth' => $policyRequest->date_of_birth->format('Y-m-d'),
                'destination' => $policyRequest->destination,
                'trip_start' => $policyRequest->trip_start->format('Y-m-d'),
                'trip_end' => $policyRequest->trip_end->format('Y-m-d'),
                'plan_code' => $policyRequest->plan_code,
                'sum_insured' => $policyRequest->sum_insured,
                'premium' => $policyRequest->calculated_premium,
            ];

            if ($scenario) {
                $requestPayload['scenario'] = $scenario;
            }

            // Record the attempt
            $attempt = IssuanceAttempt::create([
                'policy_request_id' => $policyRequest->id,
                'attempt_number' => $attemptNumber,
                'scenario' => $scenario,
                'request_payload' => $requestPayload,
            ]);

            return $policyRequest;
        });
    }

    /**
     * Call the mock insurer and process the response.
     * This is separated from submitToInsurer to allow the transaction to commit first.
     */
    public function callInsurer(PolicyRequest $policyRequest, ?string $scenario = null): PolicyRequest
    {
        $policyRequest = $this->submitToInsurer($policyRequest, $scenario);

        if ($policyRequest->status !== 'SUBMITTED') {
            return $policyRequest;
        }

        $attempt = $policyRequest->issuanceAttempts()->latest()->first();

        try {
            $scenarioUpper = strtoupper($scenario ?? 'SUCCESS');

            if ($scenarioUpper === 'TIMEOUT_AFTER_ISSUE') {
                // Simulate timeout: provider created policy internally but connection timed out
                $policyNumber = 'POL-' . strtoupper(\Illuminate\Support\Str::random(8));
                $providerRef = 'REF-' . strtoupper(\Illuminate\Support\Str::random(10));
                cache()->put(
                    "mock_insurer_issued_{$policyRequest->id}",
                    [
                        'policy_number' => $policyNumber,
                        'provider_reference' => $providerRef,
                        'status' => 'ISSUED',
                    ],
                    now()->addHours(24)
                );

                throw new \Illuminate\Http\Client\ConnectionException("Request timed out calling mock insurer (TIMEOUT_AFTER_ISSUE scenario).");
            }

            if (config('services.mock_insurer.use_http', false)) {
                $baseUrl = config('app.url', 'http://nginx');
                $response = Http::timeout(10)->post("{$baseUrl}/api/mock-insurer/process", [
                    'policy_request_id' => $policyRequest->id,
                    'uuid' => $policyRequest->uuid,
                    'scenario' => $scenario ?? 'SUCCESS',
                    'premium' => $policyRequest->calculated_premium,
                    'customer_name' => $policyRequest->customer_name,
                ]);
                $responseData = $response->json();
            } else {
                $mockRequest = \Illuminate\Http\Request::create('/api/mock-insurer/process', 'POST', [
                    'policy_request_id' => $policyRequest->id,
                    'uuid' => $policyRequest->uuid,
                    'scenario' => $scenario ?? 'SUCCESS',
                    'premium' => $policyRequest->calculated_premium,
                    'customer_name' => $policyRequest->customer_name,
                ]);
                $mockResponse = app(\App\Http\Controllers\Api\MockInsurerController::class)->process($mockRequest);
                $responseData = json_decode($mockResponse->getContent(), true) ?? [];
            }

            $attempt->update([
                'response_payload' => $responseData,
            ]);

            return $this->processInsurerResponse($policyRequest, $attempt, $responseData);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Timeout scenario - insurer may have processed but we don't know
            Log::warning("Timeout calling insurer for policy request #{$policyRequest->id}: {$e->getMessage()}");

            $attempt->update([
                'result' => 'TIMEOUT',
                'error_message' => $e->getMessage(),
            ]);

            $this->transitionState(
                $policyRequest,
                'NEEDS_RECONCILIATION',
                'Insurer call timed out - needs reconciliation',
                'system'
            );

            return $policyRequest->fresh();

        } catch (\Exception $e) {
            Log::error("Error calling insurer for policy request #{$policyRequest->id}: {$e->getMessage()}");

            $attempt->update([
                'result' => 'FAILURE',
                'error_message' => $e->getMessage(),
            ]);

            $this->transitionState(
                $policyRequest,
                'FAILED',
                "Insurer call failed: {$e->getMessage()}",
                'system'
            );

            return $policyRequest->fresh();
        }
    }

    /**
     * Process the mock insurer response and update state accordingly.
     */
    private function processInsurerResponse(
        PolicyRequest $policyRequest,
        IssuanceAttempt $attempt,
        array $responseData
    ): PolicyRequest {
        $status = $responseData['status'] ?? 'UNKNOWN';

        switch ($status) {
            case 'SUCCESS':
                $attempt->update([
                    'result' => 'SUCCESS',
                    'provider_reference' => $responseData['provider_reference'] ?? null,
                    'policy_number' => $responseData['policy_number'] ?? null,
                ]);

                // Use DB transaction with lock to prevent double-issuance
                return DB::transaction(function () use ($policyRequest, $responseData) {
                    $locked = PolicyRequest::lockForUpdate()->find($policyRequest->id);

                    if ($locked->status === 'ISSUED') {
                        return $locked; // Already issued, idempotent
                    }

                    $locked->policy_number = $responseData['policy_number'] ?? null;
                    $locked->provider_reference = $responseData['provider_reference'] ?? null;
                    $locked->save();

                    $this->transitionState($locked, 'ISSUED', 'Policy issued successfully', 'insurer');

                    return $locked->fresh();
                });

            case 'PENDING':
                $attempt->update([
                    'result' => 'PENDING',
                    'provider_reference' => $responseData['provider_reference'] ?? null,
                ]);

                $policyRequest->provider_reference = $responseData['provider_reference'] ?? null;
                $policyRequest->save();

                $this->transitionState(
                    $policyRequest,
                    'PENDING_CONFIRMATION',
                    'Awaiting insurer confirmation callback',
                    'insurer'
                );

                return $policyRequest->fresh();

            case 'FAILURE':
                $attempt->update([
                    'result' => 'FAILURE',
                    'error_message' => $responseData['error'] ?? 'Unknown failure',
                ]);

                $policyRequest->last_error = $responseData['error'] ?? 'Unknown failure';
                $policyRequest->save();

                $this->transitionState(
                    $policyRequest,
                    'FAILED',
                    $responseData['error'] ?? 'Insurer returned failure',
                    'insurer'
                );

                return $policyRequest->fresh();

            default:
                $attempt->update([
                    'result' => 'FAILURE',
                    'error_message' => "Unknown response status: {$status}",
                ]);

                $this->transitionState(
                    $policyRequest,
                    'FAILED',
                    "Unknown insurer response status: {$status}",
                    'insurer'
                );

                return $policyRequest->fresh();
        }
    }

    /**
     * Process a provider callback event idempotently.
     * Duplicate callbacks with the same event_id are safely ignored.
     */
    public function processCallback(array $eventData): array
    {
        $eventId = $eventData['event_id'] ?? null;
        $policyRequestId = $eventData['policy_request_id'] ?? null;
        $eventType = $eventData['event_type'] ?? null;

        if (!$eventId || !$policyRequestId || !$eventType) {
            return [
                'status' => 'error',
                'message' => 'Missing required fields: event_id, policy_request_id, event_type',
            ];
        }

        // Check for duplicate event using database unique constraint
        $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
        if ($existingEvent) {
            Log::info("Duplicate callback received for event_id: {$eventId}. Ignoring.");
            return [
                'status' => 'duplicate',
                'message' => "Event {$eventId} has already been processed.",
                'original_processed_at' => $existingEvent->processed_at?->toIso8601String(),
            ];
        }

        $policyRequest = PolicyRequest::find($policyRequestId);
        if (!$policyRequest) {
            return [
                'status' => 'error',
                'message' => "Policy request #{$policyRequestId} not found.",
            ];
        }

        return DB::transaction(function () use ($eventData, $eventId, $policyRequest, $eventType) {
            // Try to create the event - will fail on duplicate due to unique constraint
            try {
                $event = ProviderEvent::create([
                    'event_id' => $eventId,
                    'policy_request_id' => $policyRequest->id,
                    'event_type' => $eventType,
                    'provider_reference' => $eventData['provider_reference'] ?? null,
                    'policy_number' => $eventData['policy_number'] ?? null,
                    'payload' => $eventData,
                    'processed' => true,
                    'processed_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Race condition: another request already processed this event
                Log::info("Race condition caught for duplicate event_id: {$eventId}");
                return [
                    'status' => 'duplicate',
                    'message' => "Event {$eventId} was processed by a concurrent request.",
                ];
            }

            // Lock the policy request to process the event
            $policyRequest = PolicyRequest::lockForUpdate()->find($policyRequest->id);

            // If already ISSUED, acknowledge but don't change state
            if ($policyRequest->status === 'ISSUED') {
                $event->update([
                    'processing_notes' => 'Policy already issued. Event acknowledged but no state change.',
                ]);

                return [
                    'status' => 'acknowledged',
                    'message' => 'Policy is already issued. Callback acknowledged.',
                ];
            }

            // Process based on event type
            switch ($eventType) {
                case 'POLICY_ISSUED':
                    $policyRequest->policy_number = $eventData['policy_number'] ?? null;
                    $policyRequest->provider_reference = $eventData['provider_reference'] ?? null;
                    $policyRequest->save();

                    if ($policyRequest->canTransitionTo('ISSUED')) {
                        $this->transitionState(
                            $policyRequest,
                            'ISSUED',
                            'Confirmed via provider callback',
                            'callback'
                        );
                    }

                    return [
                        'status' => 'processed',
                        'message' => 'Policy issued successfully via callback.',
                        'policy_number' => $policyRequest->policy_number,
                    ];

                case 'POLICY_FAILED':
                    $policyRequest->last_error = $eventData['error'] ?? 'Provider reported failure';
                    $policyRequest->save();

                    if ($policyRequest->canTransitionTo('FAILED')) {
                        $this->transitionState(
                            $policyRequest,
                            'FAILED',
                            $eventData['error'] ?? 'Provider reported failure via callback',
                            'callback'
                        );
                    }

                    return [
                        'status' => 'processed',
                        'message' => 'Policy failure recorded via callback.',
                    ];

                default:
                    $event->update([
                        'processing_notes' => "Unknown event type: {$eventType}",
                    ]);

                    return [
                        'status' => 'ignored',
                        'message' => "Unknown event type: {$eventType}",
                    ];
            }
        });
    }

    /**
     * Reconcile a policy request by checking status with the mock insurer.
     * Useful for requests stuck in NEEDS_RECONCILIATION or PENDING_CONFIRMATION.
     */
    public function reconcile(PolicyRequest $policyRequest): array
    {
        if (!in_array($policyRequest->status, ['NEEDS_RECONCILIATION', 'PENDING_CONFIRMATION'])) {
            return [
                'status' => 'skipped',
                'message' => "Policy request is in '{$policyRequest->status}', no reconciliation needed.",
            ];
        }

        try {
            if (config('services.mock_insurer.use_http', false)) {
                $baseUrl = config('app.url', 'http://nginx');
                $response = Http::timeout(5)->get("{$baseUrl}/api/mock-insurer/status/{$policyRequest->id}");
                $statusCode = $response->status();
                $data = $response->json();
            } else {
                $mockResponse = app(\App\Http\Controllers\Api\MockInsurerController::class)->status($policyRequest->id);
                $statusCode = $mockResponse->getStatusCode();
                $data = json_decode($mockResponse->getContent(), true) ?? [];
            }

            if ($statusCode === 200 && ($data['status'] ?? '') === 'found' && isset($data['data'])) {
                $policyData = $data['data'];

                return DB::transaction(function () use ($policyRequest, $policyData) {
                    $locked = PolicyRequest::lockForUpdate()->find($policyRequest->id);
                    if ($locked->status === 'ISSUED') {
                        return ['status' => 'already_issued', 'message' => 'Policy already issued.'];
                    }

                    $locked->policy_number = $policyData['policy_number'] ?? null;
                    $locked->provider_reference = $policyData['provider_reference'] ?? null;
                    $locked->save();

                    if ($locked->canTransitionTo('ISSUED')) {
                        $this->transitionState($locked, 'ISSUED', 'Reconciled: verified issued at provider', 'reconciliation');
                    }

                    return [
                        'status' => 'reconciled_issued',
                        'policy_number' => $locked->policy_number,
                        'provider_reference' => $locked->provider_reference,
                    ];
                });
            } elseif ($statusCode === 404) {
                // If provider has no record and request needed reconciliation, mark as FAILED
                return DB::transaction(function () use ($policyRequest) {
                    $locked = PolicyRequest::lockForUpdate()->find($policyRequest->id);
                    if ($locked->canTransitionTo('FAILED')) {
                        $this->transitionState($locked, 'FAILED', 'Reconciled: provider has no record of issuance', 'reconciliation');
                    }
                    return [
                        'status' => 'reconciled_failed',
                        'message' => 'Provider has no record of this request. Marked as FAILED.',
                    ];
                });
            }

            return [
                'status' => 'pending',
                'message' => 'Provider still processing or unreachable.',
            ];

        } catch (\Exception $e) {
            Log::error("Error reconciling policy request #{$policyRequest->id}: {$e->getMessage()}");
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
