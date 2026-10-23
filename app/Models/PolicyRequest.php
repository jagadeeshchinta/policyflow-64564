<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PolicyRequest extends Model
{
    protected $fillable = [
        'uuid',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'calculated_premium',
        'travel_days',
        'age_multiplier',
        'destination_multiplier',
        'coverage_multiplier',
        'base_daily_rate',
        'status',
        'policy_number',
        'provider_reference',
        'attempt_count',
        'last_error',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start' => 'date',
        'trip_end' => 'date',
        'sum_insured' => 'decimal:2',
        'calculated_premium' => 'decimal:2',
        'age_multiplier' => 'decimal:2',
        'destination_multiplier' => 'decimal:2',
        'coverage_multiplier' => 'decimal:2',
        'base_daily_rate' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (PolicyRequest $policyRequest) {
            if (empty($policyRequest->uuid)) {
                $policyRequest->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Valid state transitions map.
     * Key = current state, Value = array of allowed next states.
     */
    public const STATE_TRANSITIONS = [
        'RECEIVED' => ['VALIDATED', 'FAILED'],
        'VALIDATED' => ['SUBMITTED', 'FAILED'],
        'SUBMITTED' => ['PENDING_CONFIRMATION', 'ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'PENDING_CONFIRMATION' => ['ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'NEEDS_RECONCILIATION' => ['ISSUED', 'FAILED'],
        // Terminal states - no further transitions allowed
        'ISSUED' => [],
        'FAILED' => ['SUBMITTED'], // Allow retry from FAILED
    ];

    /**
     * Check if a state transition is valid.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::STATE_TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowed);
    }

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('created_at', 'asc');
    }
}
