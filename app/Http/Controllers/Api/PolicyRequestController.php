<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRequestRequest;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use App\Services\PremiumCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PolicyRequestController extends Controller
{
    public function __construct(
        private PremiumCalculator $premiumCalculator,
        private IssuanceService $issuanceService
    ) {}

    /**
     * POST /api/policy-requests
     * Create a new policy request or safely return existing one (idempotent).
     */
    public function store(StorePolicyRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Check for existing request with same agent_id + client_request_id (idempotent)
        $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
            ->where('client_request_id', $validated['client_request_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'existing',
                'message' => 'A policy request with this agent_id and client_request_id already exists.',
                'data' => $this->formatPolicyRequest($existing),
            ], 200);
        }

        try {
            $policyRequest = DB::transaction(function () use ($validated) {
                // Calculate premium
                $premiumResult = $this->premiumCalculator->calculate(
                    $validated['plan_code'],
                    $validated['date_of_birth'],
                    $validated['trip_start'],
                    $validated['trip_end'],
                    $validated['destination'],
                    (string) $validated['sum_insured']
                );

                // Create the policy request
                $policyRequest = PolicyRequest::create([
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id' => $validated['agent_id'],
                    'customer_name' => $validated['customer_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'destination' => $validated['destination'],
                    'trip_start' => $validated['trip_start'],
                    'trip_end' => $validated['trip_end'],
                    'plan_code' => $validated['plan_code'],
                    'sum_insured' => $validated['sum_insured'],
                    'calculated_premium' => $premiumResult['premium'],
                    'travel_days' => $premiumResult['travel_days'],
                    'base_daily_rate' => $premiumResult['base_daily_rate'],
                    'age_multiplier' => $premiumResult['age_multiplier'],
                    'destination_multiplier' => $premiumResult['destination_multiplier'],
                    'coverage_multiplier' => $premiumResult['coverage_multiplier'],
                    'status' => 'RECEIVED',
                ]);

                // Transition to VALIDATED (auto-validation since we passed form validation)
                $this->issuanceService->transitionState(
                    $policyRequest,
                    'VALIDATED',
                    'Request validated successfully',
                    'system'
                );

                return $policyRequest->fresh();
            });

            return response()->json([
                'status' => 'created',
                'message' => 'Policy request created and validated successfully.',
                'data' => $this->formatPolicyRequest($policyRequest),
            ], 201);

        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race condition: another concurrent request created it first
            $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
                ->where('client_request_id', $validated['client_request_id'])
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'existing',
                    'message' => 'A policy request with this agent_id and client_request_id already exists (concurrent creation).',
                    'data' => $this->formatPolicyRequest($existing),
                ], 200);
            }

            throw $e;
        }
    }

    /**
     * GET /api/policy-requests/{id}
     * Retrieve status, premium, and references for a policy request.
     */
    public function show(int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with(['stateHistory', 'issuanceAttempts', 'providerEvents'])->find($id);

        if (!$policyRequest) {
            return response()->json([
                'status' => 'error',
                'message' => "Policy request #{$id} not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatPolicyRequestDetailed($policyRequest),
        ]);
    }

    /**
     * GET /api/policy-requests
     * List requests with status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        // Status filter
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Agent filter
        if ($request->has('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 15), 100);
        $results = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $results->getCollection()->map(fn($pr) => $this->formatPolicyRequest($pr)),
            'pagination' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Start or safely resume issuance.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $policyRequest = PolicyRequest::find($id);

        if (!$policyRequest) {
            return response()->json([
                'status' => 'error',
                'message' => "Policy request #{$id} not found.",
            ], 404);
        }

        // If already ISSUED, return safely (idempotent)
        if ($policyRequest->status === 'ISSUED') {
            return response()->json([
                'status' => 'success',
                'message' => 'Policy is already issued.',
                'data' => $this->formatPolicyRequest($policyRequest),
            ], 200);
        }

        // Only allow submission from VALIDATED or FAILED
        if (!in_array($policyRequest->status, ['VALIDATED', 'FAILED'])) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot submit policy request in status '{$policyRequest->status}'. Must be VALIDATED or FAILED.",
            ], 422);
        }

        $scenario = $request->input('scenario', 'SUCCESS');

        try {
            $policyRequest = $this->issuanceService->callInsurer($policyRequest, $scenario);

            return response()->json([
                'status' => 'success',
                'message' => "Policy request submitted. Current status: {$policyRequest->status}.",
                'data' => $this->formatPolicyRequest($policyRequest),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to submit policy request #{$id}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => "Submission failed: {$e->getMessage()}",
            ], 500);
        }
    }

    /**
     * Format a policy request for API response.
     */
    private function formatPolicyRequest(PolicyRequest $pr): array
    {
        return [
            'id' => $pr->id,
            'uuid' => $pr->uuid,
            'client_request_id' => $pr->client_request_id,
            'agent_id' => $pr->agent_id,
            'customer_name' => $pr->customer_name,
            'date_of_birth' => $pr->date_of_birth->format('Y-m-d'),
            'destination' => $pr->destination,
            'trip_start' => $pr->trip_start->format('Y-m-d'),
            'trip_end' => $pr->trip_end->format('Y-m-d'),
            'plan_code' => $pr->plan_code,
            'sum_insured' => $pr->sum_insured,
            'calculated_premium' => $pr->calculated_premium,
            'travel_days' => $pr->travel_days,
            'premium_breakdown' => [
                'base_daily_rate' => $pr->base_daily_rate,
                'age_multiplier' => $pr->age_multiplier,
                'destination_multiplier' => $pr->destination_multiplier,
                'coverage_multiplier' => $pr->coverage_multiplier,
            ],
            'status' => $pr->status,
            'policy_number' => $pr->policy_number,
            'provider_reference' => $pr->provider_reference,
            'attempt_count' => $pr->attempt_count,
            'last_error' => $pr->last_error,
            'created_at' => $pr->created_at->toIso8601String(),
            'updated_at' => $pr->updated_at->toIso8601String(),
        ];
    }

    /**
     * Format a policy request with full audit details.
     */
    private function formatPolicyRequestDetailed(PolicyRequest $pr): array
    {
        $data = $this->formatPolicyRequest($pr);

        $data['state_history'] = $pr->stateHistory->map(fn($h) => [
            'from_status' => $h->from_status,
            'to_status' => $h->to_status,
            'reason' => $h->reason,
            'triggered_by' => $h->triggered_by,
            'timestamp' => $h->created_at->toIso8601String(),
        ]);

        $data['issuance_attempts'] = $pr->issuanceAttempts->map(fn($a) => [
            'attempt_number' => $a->attempt_number,
            'scenario' => $a->scenario,
            'result' => $a->result,
            'provider_reference' => $a->provider_reference,
            'policy_number' => $a->policy_number,
            'error_message' => $a->error_message,
            'timestamp' => $a->created_at->toIso8601String(),
        ]);

        $data['provider_events'] = $pr->providerEvents->map(fn($e) => [
            'event_id' => $e->event_id,
            'event_type' => $e->event_type,
            'provider_reference' => $e->provider_reference,
            'policy_number' => $e->policy_number,
            'processed' => $e->processed,
            'processed_at' => $e->processed_at?->toIso8601String(),
            'timestamp' => $e->created_at->toIso8601String(),
        ]);

        return $data;
    }
}
