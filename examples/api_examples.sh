#!/bin/bash
# PolicyFlow API Examples
# Run these commands after the application is up at http://localhost:8000

echo "=== 1. Create a Policy Request ==="
curl -s -X POST http://localhost:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python -m json.tool

echo ""
echo "=== 2. Duplicate Request (same agent_id + client_request_id) ==="
curl -s -X POST http://localhost:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python -m json.tool

echo ""
echo "=== 3. Get Policy Request Details ==="
curl -s http://localhost:8000/api/policy-requests/1 | python -m json.tool

echo ""
echo "=== 4. List All Policy Requests ==="
curl -s "http://localhost:8000/api/policy-requests?per_page=10" | python -m json.tool

echo ""
echo "=== 5. Submit for Issuance (SUCCESS scenario) ==="
curl -s -X POST http://localhost:8000/api/policy-requests/1/submit \
  -H "Content-Type: application/json" \
  -d '{"scenario": "SUCCESS"}' | python -m json.tool

echo ""
echo "=== 6. Create another request and test DELAYED_CALLBACK ==="
curl -s -X POST http://localhost:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0002",
    "agent_id": "AGENT-17",
    "customer_name": "Jane Doe",
    "date_of_birth": "1990-05-20",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-01",
    "trip_end": "2026-12-05",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }' | python -m json.tool

echo ""
echo "=== 7. Submit with DELAYED_CALLBACK scenario ==="
curl -s -X POST http://localhost:8000/api/policy-requests/2/submit \
  -H "Content-Type: application/json" \
  -d '{"scenario": "DELAYED_CALLBACK"}' | python -m json.tool

echo ""
echo "=== 8. Send Callback (simulating provider event) ==="
curl -s -X POST http://localhost:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "EVT-CALLBACK-001",
    "policy_request_id": 2,
    "event_type": "POLICY_ISSUED",
    "policy_number": "POL-MANUAL-001",
    "provider_reference": "REF-MANUAL-001"
  }' | python -m json.tool

echo ""
echo "=== 9. Send DUPLICATE Callback (same event_id) ==="
curl -s -X POST http://localhost:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "EVT-CALLBACK-001",
    "policy_request_id": 2,
    "event_type": "POLICY_ISSUED",
    "policy_number": "POL-MANUAL-001",
    "provider_reference": "REF-MANUAL-001"
  }' | python -m json.tool

echo ""
echo "=== 10. Verify final state ==="
curl -s http://localhost:8000/api/policy-requests/2 | python -m json.tool

echo ""
echo "=== 11. Filter by status ==="
curl -s "http://localhost:8000/api/policy-requests?status=ISSUED" | python -m json.tool

echo ""
echo "=== 12. Invalid request (missing fields) ==="
curl -s -X POST http://localhost:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{}' | python -m json.tool

echo ""
echo "=== 13. Invalid request (bad dates) ==="
curl -s -X POST http://localhost:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0099",
    "agent_id": "AGENT-17",
    "customer_name": "Bad Dates",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-12-10",
    "trip_end": "2026-12-05",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python -m json.tool
