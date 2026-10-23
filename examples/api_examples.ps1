# PolicyFlow API Examples (PowerShell)
# Run this script while Docker containers are running (http://localhost:8000)

Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "  PolicyFlow - Insurance Engine Live API Demo" -ForegroundColor Cyan
Write-Host "=================================================" -ForegroundColor Cyan

# 1. Create a Policy Request
Write-Host "`n[1] Creating a Policy Request..." -ForegroundColor Yellow
$body1 = @{
    client_request_id = "AGT-2026-0001"
    agent_id = "AGENT-17"
    customer_name = "Sample Customer"
    date_of_birth = "1998-04-10"
    destination = "INTERNATIONAL"
    trip_start = "2026-11-10"
    trip_end = "2026-11-14"
    plan_code = "PLUS"
    sum_insured = 300000
} | ConvertTo-Json

$res1 = Invoke-RestMethod -Uri "http://localhost:8000/api/policy-requests" -Method Post -Body $body1 -ContentType "application/json"
$res1 | ConvertTo-Json -Depth 5

# 2. Test Idempotent Duplicate Intake
Write-Host "`n[2] Testing Duplicate Intake (same agent_id + client_request_id)..." -ForegroundColor Yellow
$res2 = Invoke-RestMethod -Uri "http://localhost:8000/api/policy-requests" -Method Post -Body $body1 -ContentType "application/json"
$res2 | ConvertTo-Json -Depth 5

# 3. Submit for Issuance (SUCCESS scenario)
$policyId = $res1.data.id
Write-Host "`n[3] Submitting Policy #$policyId for Issuance..." -ForegroundColor Yellow
$submitBody = @{ scenario = "SUCCESS" } | ConvertTo-Json
$res3 = Invoke-RestMethod -Uri "http://localhost:8000/api/policy-requests/$policyId/submit" -Method Post -Body $submitBody -ContentType "application/json"
$res3 | ConvertTo-Json -Depth 5

# 4. Fetch Full Audit Trail
Write-Host "`n[4] Fetching Policy Details and Audit Trail..." -ForegroundColor Yellow
$res4 = Invoke-RestMethod -Uri "http://localhost:8000/api/policy-requests/$policyId" -Method Get
$res4 | ConvertTo-Json -Depth 6

# 5. Test Duplicate Callback Idempotency
Write-Host "`n[5] Testing Duplicate Callback Idempotency..." -ForegroundColor Yellow
$callbackBody = @{
    event_id = "EVT-DEMO-DUP-01"
    policy_request_id = $policyId
    event_type = "POLICY_ISSUED"
    policy_number = "POL-DUP-CHECK"
    provider_reference = "REF-DUP-CHECK"
} | ConvertTo-Json

Write-Host "Sending first callback:"
$cb1 = Invoke-RestMethod -Uri "http://localhost:8000/api/mock-insurer/callback" -Method Post -Body $callbackBody -ContentType "application/json"
$cb1 | ConvertTo-Json

Write-Host "Sending duplicate callback (same event_id):"
$cb2 = Invoke-RestMethod -Uri "http://localhost:8000/api/mock-insurer/callback" -Method Post -Body $callbackBody -ContentType "application/json"
$cb2 | ConvertTo-Json

# 6. List and Filter Policies
Write-Host "`n[6] Listing Policies with Filter (status=ISSUED)..." -ForegroundColor Yellow
$res6 = Invoke-RestMethod -Uri "http://localhost:8000/api/policy-requests?status=ISSUED" -Method Get
$res6 | ConvertTo-Json -Depth 5

Write-Host "`nDemo completed successfully!" -ForegroundColor Green
