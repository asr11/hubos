#!/bin/bash
echo "Starting backend..."
PORT=8788 npx tsx server/src/index.ts > backend_test.log 2>&1 &
SERVER_PID=$!
echo "Waiting for server to start..."
sleep 5

echo "Testing GET (expect 401)..."
curl -s -i http://localhost:8788/api/people || echo "curl GET failed"

echo -e "\nTesting POST (expect 401)..."
curl -s -i -X POST http://localhost:8788/api/people -H "Content-Type: application/json" -d '{"full_name": "Test", "phone": "0501234567"}' || echo "curl POST failed"

echo -e "\nTesting DELETE (expect 401)..."
curl -s -i -X DELETE http://localhost:8788/api/people/123 || echo "curl DELETE failed"

kill $SERVER_PID
echo -e "\nKilled backend"
