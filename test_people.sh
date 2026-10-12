#!/bin/bash
echo "Starting server..."
npm run dev > server_test.log 2>&1 &
SERVER_PID=$!
sleep 15

echo "Testing GET without token (expect 401)..."
curl -s -i http://localhost:8788/api/people || echo "curl failed"

echo -e "\nTesting POST without token (expect 401)..."
curl -s -i -X POST http://localhost:8788/api/people -H "Content-Type: application/json" -d '{"full_name": "Test", "phone": "0501234567"}' || echo "curl failed"

echo -e "\nTesting DELETE without token (expect 401)..."
curl -s -i -X DELETE http://localhost:8788/api/people/123 || echo "curl failed"

kill $SERVER_PID
echo -e "\nKilled server"
