<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('policyflow:reconcile {--id= : Specific Policy Request ID to reconcile}', function (\App\Services\IssuanceService $issuanceService) {
    $id = $this->option('id');

    $query = \App\Models\PolicyRequest::query();
    if ($id) {
        $query->where('id', $id);
    } else {
        $query->whereIn('status', ['NEEDS_RECONCILIATION', 'PENDING_CONFIRMATION']);
    }

    $requests = $query->get();

    if ($requests->isEmpty()) {
        $this->info("No policy requests require reconciliation.");
        return 0;
    }

    $this->info("Found {$requests->count()} request(s) requiring reconciliation.");

    $rows = [];
    foreach ($requests as $policyRequest) {
        $result = $issuanceService->reconcile($policyRequest);
        $fresh = $policyRequest->fresh();

        $rows[] = [
            'ID' => $policyRequest->id,
            'Client Req ID' => $policyRequest->client_request_id,
            'Old Status' => $policyRequest->status,
            'New Status' => $fresh->status,
            'Policy Number' => $fresh->policy_number ?? 'N/A',
            'Result' => $result['status'] ?? 'unknown',
        ];
    }

    $this->table(['ID', 'Client Req ID', 'Old Status', 'New Status', 'Policy Number', 'Result'], $rows);
    return 0;
})->purpose('Reconcile pending or timed-out insurance policy requests with the insurer');
