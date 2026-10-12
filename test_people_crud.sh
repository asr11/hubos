#!/bin/bash
cd server
npx tsx src/index.ts > test.log 2>&1 &
SERVER_PID=$!

echo "Waiting for server to start..."
sleep 5

echo -e "\n--- GET /api/people (Initial) ---"
curl -s -H "Authorization: Bearer hub_os_token" http://127.0.0.1:3001/api/people

echo -e "\n\n--- POST /api/people ---"
curl -s -X POST -H "Authorization: Bearer hub_os_token" -H "Content-Type: application/json" -d '{"full_name":"Test CEO","phone":"0501234567","role":"lead"}' http://127.0.0.1:3001/api/people

echo -e "\n\n--- GET /api/people (After POST) ---"
curl -s -H "Authorization: Bearer hub_os_token" http://127.0.0.1:3001/api/people > res.json
cat res.json

# Extract ID
ID=$(cat res.json | grep -o '"id":"[^"]*"' | head -n 1 | cut -d'"' -f4)
echo -e "\n\nExtracted ID: $ID"

if [ -n "$ID" ]; then
  echo -e "\n--- DELETE /api/people/:id ---"
  curl -s -X DELETE -H "Authorization: Bearer hub_os_token" http://127.0.0.1:3001/api/people/$ID

  echo -e "\n\n--- GET /api/people (After DELETE) ---"
  curl -s -H "Authorization: Bearer hub_os_token" http://127.0.0.1:3001/api/people
fi

kill $SERVER_PID
