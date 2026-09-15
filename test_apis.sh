#!/bin/bash
TOKEN="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1aWQiOiJhZG1pbiIsInJvbGUiOiJhZG1pbiIsImVtYWlsIjoiYWRtaW5AaHViLmNvLmlsIiwiaWF0IjoxNzg5MzI0NjAzLCJleHAiOjE3ODkzMjgyMDN9.L2X1NzrezS74iHLr0BZuOrSPUPGWh12mBjiHoLZ1mwo"
BASE_URL="http://127.0.0.1:8787"
CURL_CMD="curl -s -w '\n%{http_code}\n'"

echo "--- GET /health ---"
$CURL_CMD "$BASE_URL/health"

echo "--- GET /api/me ---"
$CURL_CMD -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/me"

echo "--- GET /api/users ---"
$CURL_CMD -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/users"

echo "--- GET /api/auth/users ---"
$CURL_CMD -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/auth/users"

echo "--- POST /api/auth/users ---"
$CURL_CMD -X POST -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" -d '{"email":"test@hub.co.il","password":"123"}' "$BASE_URL/api/auth/users"

echo "--- GET /api/neural-agents/nodes ---"
$CURL_CMD "$BASE_URL/api/neural-agents/nodes"

echo "--- GET /api/tasks (CRUD test) ---"
$CURL_CMD -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/tasks"

echo "--- POST /api/tasks (CRUD test) ---"
$CURL_CMD -X POST -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" -d '{"title":"Test task"}' "$BASE_URL/api/tasks"
