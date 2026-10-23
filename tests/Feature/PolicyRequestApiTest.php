<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validPayload = [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];
    }

    // ================================================================
    // POLICY REQUEST CREATION TESTS
    // ================================================================

    /**
     * Test: Valid request produces expected premium and status.
     * Tests the exact example from the assignment spec.
     */
    public function test_create_valid_policy_request_with_correct_premium(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'created',
            ])
            ->assertJsonPath('data.calculated_premium', '225.00')
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.travel_days', 5)
            ->assertJsonPath('data.premium_breakdown.base_daily_rate', '20.00')
            ->assertJsonPath('data.premium_breakdown.age_multiplier', '1.00')
            ->assertJsonPath('data.premium_breakdown.destination_multiplier', '1.80')
            ->assertJsonPath('data.premium_breakdown.coverage_multiplier', '1.25');

        $this->assertDatabaseCount('policy_requests', 1);
    }

    /**
     * Test: Invalid travel dates are rejected.
     * trip_end < trip_start should fail validation.
     */
    public function test_invalid_travel_dates_rejected(): void
    {
        $payload = $this->validPayload;
        $payload['trip_start'] = '2026-12-10';
        $payload['trip_end'] = '2026-12-05'; // Before start

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['errors' => ['trip_end']]);
    }

    /**
     * Test: Unsupported plan is rejected.
     */
    public function test_unsupported_plan_rejected(): void
    {
        $payload = $this->validPayload;
        $payload['plan_code'] = 'GOLD';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['plan_code']]);
    }

    /**
     * Test: Unsupported sum_insured is rejected.
     */
    public function test_unsupported_sum_insured_rejected(): void
    {
        $payload = $this->validPayload;
        $payload['sum_insured'] = 200000;

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sum_insured']]);
    }

    /**
     * Test: Missing required fields are rejected with validation errors.
     */
    public function test_missing_required_fields_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'client_request_id',
                    'agent_id',
                    'customer_name',
                    'date_of_birth',
                    'destination',
                    'trip_start',
                    'trip_end',
                    'plan_code',
                    'sum_insured',
                ],
            ]);
    }

    /**
     * Test: Invalid destination is rejected.
     */
    public function test_invalid_destination_rejected(): void
    {
        $payload = $this->validPayload;
        $payload['destination'] = 'MARS';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['destination']]);
    }

    // ================================================================
    // DUPLICATE INTAKE TESTS (Reliability Scenario #1)
    // ================================================================

    /**
     * Test: Repeated agent_id + client_request_id does NOT create another logical request.
     * This is the core idempotency requirement.
     */
    public function test_duplicate_intake_returns_existing_request(): void
    {
        // First request - creates new
        $response1 = $this->postJson('/api/policy-requests', $this->validPayload);
        $response1->assertStatus(201);
        $createdId = $response1->json('data.id');

        // Second request - same agent_id + client_request_id
        $response2 = $this->postJson('/api/policy-requests', $this->validPayload);
        $response2->assertStatus(200)
            ->assertJson([
                'status' => 'existing',
            ])
            ->assertJsonPath('data.id', $createdId);

        // Only ONE record in the database
        $this->assertDatabaseCount('policy_requests', 1);
    }

    /**
     * Test: Different agent_id with same client_request_id creates a new request.
     */
    public function test_different_agent_creates_separate_request(): void
    {
        // First agent
        $this->postJson('/api/policy-requests', $this->validPayload)->assertStatus(201);

        // Different agent, same client_request_id
        $payload2 = $this->validPayload;
        $payload2['agent_id'] = 'AGENT-99';
        $this->postJson('/api/policy-requests', $payload2)->assertStatus(201);

        $this->assertDatabaseCount('policy_requests', 2);
    }

    /**
     * Test: Same agent_id with different client_request_id creates a new request.
     */
    public function test_same_agent_different_request_id_creates_new(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload)->assertStatus(201);

        $payload2 = $this->validPayload;
        $payload2['client_request_id'] = 'AGT-2026-0002';
        $this->postJson('/api/policy-requests', $payload2)->assertStatus(201);

        $this->assertDatabaseCount('policy_requests', 2);
    }

    // ================================================================
    // RETRIEVE AND LIST TESTS
    // ================================================================

    /**
     * Test: Can retrieve a policy request by ID.
     */
    public function test_can_retrieve_policy_request_by_id(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $response = $this->getJson("/api/policy-requests/{$id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.customer_name', 'Sample Customer')
            ->assertJsonStructure([
                'data' => [
                    'state_history',
                    'issuance_attempts',
                    'provider_events',
                ],
            ]);
    }

    /**
     * Test: Returns 404 for non-existent policy request.
     */
    public function test_returns_404_for_nonexistent_request(): void
    {
        $response = $this->getJson('/api/policy-requests/99999');
        $response->assertStatus(404);
    }

    /**
     * Test: Can list policy requests with pagination.
     */
    public function test_can_list_policy_requests_with_pagination(): void
    {
        // Create 3 requests
        for ($i = 1; $i <= 3; $i++) {
            $payload = $this->validPayload;
            $payload['client_request_id'] = "AGT-2026-000{$i}";
            $this->postJson('/api/policy-requests', $payload);
        }

        $response = $this->getJson('/api/policy-requests?per_page=2');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('pagination.per_page', 2);
    }

    /**
     * Test: Can filter policy requests by status.
     */
    public function test_can_filter_by_status(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload);

        $response = $this->getJson('/api/policy-requests?status=VALIDATED');
        $response->assertStatus(200)->assertJsonCount(1, 'data');

        $response2 = $this->getJson('/api/policy-requests?status=ISSUED');
        $response2->assertStatus(200)->assertJsonCount(0, 'data');
    }

    // ================================================================
    // STATE TRANSITION TESTS
    // ================================================================

    /**
     * Test: State history is recorded for each transition.
     */
    public function test_state_history_recorded(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $response = $this->getJson("/api/policy-requests/{$id}");

        $stateHistory = $response->json('data.state_history');

        // Should have RECEIVED → VALIDATED transition
        $this->assertNotEmpty($stateHistory);
        $this->assertEquals('RECEIVED', $stateHistory[0]['from_status']);
        $this->assertEquals('VALIDATED', $stateHistory[0]['to_status']);
    }

    /**
     * Test: Cannot submit a request that is not VALIDATED or FAILED.
     */
    public function test_cannot_submit_non_validated_request(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        // Submit once (moves to SUBMITTED then processes)
        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);

        // Try to submit again when it's already ISSUED
        $response = $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);

        // Should return 200 (idempotent) since it's already ISSUED
        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');
    }

    // ================================================================
    // ISSUANCE TESTS
    // ================================================================

    /**
     * Test: Successful provider response produces exactly one policy number.
     */
    public function test_successful_issuance_produces_policy_number(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $submitResponse = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $submitResponse->assertStatus(200);

        $data = $submitResponse->json('data');
        $this->assertEquals('ISSUED', $data['status']);
        $this->assertNotNull($data['policy_number']);
        $this->assertStringStartsWith('POL-', $data['policy_number']);
    }

    /**
     * Test: A repeated callback cannot overwrite a confirmed ISSUED state.
     */
    public function test_issued_state_cannot_be_overwritten(): void
    {
        // Create and issue a policy
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$id}/submit", ['scenario' => 'SUCCESS']);

        // Verify it's ISSUED
        $policyRequest = PolicyRequest::find($id);
        $this->assertEquals('ISSUED', $policyRequest->status);
        $originalPolicyNumber = $policyRequest->policy_number;

        // Send a callback trying to override
        $callbackResponse = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-OVERRIDE-ATTEMPT',
            'policy_request_id' => $id,
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-DIFFERENT',
            'provider_reference' => 'REF-DIFFERENT',
        ]);

        $callbackResponse->assertStatus(200);

        // Policy number should NOT have changed
        $policyRequest->refresh();
        $this->assertEquals('ISSUED', $policyRequest->status);
        $this->assertEquals($originalPolicyNumber, $policyRequest->policy_number);
    }

    // ================================================================
    // DUPLICATE CALLBACK TESTS (Reliability Scenario #2)
    // ================================================================

    /**
     * Test: Duplicate callbacks with the same event_id are processed idempotently.
     */
    public function test_duplicate_callback_processed_idempotently(): void
    {
        // Create and submit a policy (to PENDING_CONFIRMATION)
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        // Send the same callback 3 times
        $callbackPayload = [
            'event_id' => 'EVT-DUPLICATE-TEST-001',
            'policy_request_id' => $id,
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-DUP-TEST',
            'provider_reference' => 'REF-DUP-TEST',
        ];

        // First callback - should be processed
        $response1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $response1->assertStatus(200)
            ->assertJsonPath('status', 'processed');

        // Second callback (duplicate) - should be recognized as duplicate
        $response2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $response2->assertStatus(200)
            ->assertJsonPath('status', 'duplicate');

        // Third callback (duplicate) - still duplicate
        $response3 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $response3->assertStatus(200)
            ->assertJsonPath('status', 'duplicate');

        // Verify only ONE provider event was recorded
        $policyRequest = PolicyRequest::find($id);
        $this->assertEquals(1, $policyRequest->providerEvents()->count());

        // Verify the policy was issued exactly once
        $this->assertEquals('ISSUED', $policyRequest->fresh()->status);
        $this->assertEquals('POL-DUP-TEST', $policyRequest->fresh()->policy_number);
    }

    /**
     * Test: Different event IDs are processed independently.
     */
    public function test_different_event_ids_processed_independently(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        // First event
        $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-FIRST',
            'policy_request_id' => $id,
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-FIRST',
            'provider_reference' => 'REF-FIRST',
        ])->assertJsonPath('status', 'processed');

        // Second event with different event_id - should be acknowledged but not change state
        $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-SECOND',
            'policy_request_id' => $id,
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-SECOND',
            'provider_reference' => 'REF-SECOND',
        ])->assertJsonPath('status', 'acknowledged'); // Already ISSUED

        // Two events recorded but state unchanged
        $policyRequest = PolicyRequest::find($id);
        $this->assertEquals(2, $policyRequest->providerEvents()->count());
        $this->assertEquals('ISSUED', $policyRequest->status);
        $this->assertEquals('POL-FIRST', $policyRequest->policy_number); // First one wins
    }

    /**
     * Test: Callback with missing event_id is rejected.
     */
    public function test_callback_without_event_id_rejected(): void
    {
        $response = $this->postJson('/api/mock-insurer/callback', [
            'policy_request_id' => 1,
            'event_type' => 'POLICY_ISSUED',
        ]);

        $response->assertStatus(422);
    }

    // ================================================================
    // TIMEOUT & RECONCILIATION TESTS (Reliability Scenario #3)
    // ================================================================

    /**
     * Test: When insurer call times out, state safely transitions to NEEDS_RECONCILIATION.
     */
    public function test_timeout_after_issue_transitions_to_needs_reconciliation(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        $submitResponse = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $submitResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'NEEDS_RECONCILIATION');

        $policyRequest = PolicyRequest::find($id);
        $this->assertEquals('NEEDS_RECONCILIATION', $policyRequest->status);

        $attempt = $policyRequest->issuanceAttempts()->latest()->first();
        $this->assertEquals('TIMEOUT', $attempt->result);
    }

    /**
     * Test: Artisan reconciliation command checks provider and safely reconciles to ISSUED.
     */
    public function test_reconciliation_command_resolves_timed_out_policy(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        // Submit with TIMEOUT_AFTER_ISSUE
        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $this->assertEquals('NEEDS_RECONCILIATION', PolicyRequest::find($id)->status);

        // Run reconciliation command
        \Illuminate\Support\Facades\Artisan::call('policyflow:reconcile', ['--id' => $id]);

        $policyRequest = PolicyRequest::find($id);
        $this->assertEquals('ISSUED', $policyRequest->status);
        $this->assertNotNull($policyRequest->policy_number);
        $this->assertStringStartsWith('POL-', $policyRequest->policy_number);
    }

    // ================================================================
    // RETRY AFTER TEMPORARY FAILURE (Reliability Scenario #4)
    // ================================================================

    /**
     * Test: Temporary failure can be retried safely without creating duplicates.
     */
    public function test_temporary_failure_and_safe_retry(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload);
        $id = $createResponse->json('data.id');

        // First attempt: TEMPORARY_FAILURE
        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $policyRequest = PolicyRequest::find($id);
        $this->assertEquals('FAILED', $policyRequest->status);
        $this->assertEquals(1, $policyRequest->attempt_count);

        // Second attempt: Retry with SUCCESS
        $retryResponse = $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $retryResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        $policyRequest->refresh();
        $this->assertEquals('ISSUED', $policyRequest->status);
        $this->assertEquals(2, $policyRequest->attempt_count);
        $this->assertNotNull($policyRequest->policy_number);
        $this->assertDatabaseCount('policy_requests', 1); // Exactly one record
    }
}
