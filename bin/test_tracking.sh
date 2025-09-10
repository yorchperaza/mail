#!/bin/bash

# Configuration
API_KEY="3b207e463b29956a.3849238753625ea35571fda80b405dabeb4974f27d2e39a159126923b157bf87"
BASE_URL="https://smtp.monkeysmail.com"
TEST_EMAIL="jorge@monkeys.cloud"

echo "=== Email Tracking Test Suite ==="
echo ""

# Step 1: Send test email
echo "1. Sending test email with tracking..."
RESPONSE=$(curl -s -X POST "$BASE_URL/messages/send" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d '{
    "from": {"email": "test@monkeyslegion.com", "name": "Track Test"},
    "to": ["'$TEST_EMAIL'"],
    "subject": "Tracking Test - '"$(date +%Y%m%d-%H%M%S)"'",
    "html": "<html><body><h1>Tracking Test</h1><p>Testing email tracking.</p><a href=\"https://example.com/test\">Test Link 1</a><br><a href=\"https://google.com\">Test Link 2</a></body></html>",
    "tracking": {"opens": true, "clicks": true}
  }')

echo "Response: $RESPONSE"
echo ""

# Extract message ID from response (if available)
MESSAGE_ID=$(echo $RESPONSE | grep -o '"id":[0-9]*' | cut -d: -f2)
echo "Message ID: $MESSAGE_ID"
echo ""

# Step 2: Wait a moment for DB write
sleep 2

# Step 3: Get tracking token (requires DB access)
echo "2. Getting tracking token from database..."
echo "Run this SQL query to get the token:"
echo "SELECT track_token FROM messagerecipients WHERE message_id = $MESSAGE_ID LIMIT 1;"
echo ""
read -p "Enter the tracking token from DB: " TOKEN

if [ -z "$TOKEN" ]; then
    echo "No token provided, exiting"
    exit 1
fi

echo ""
echo "3. Testing open tracking..."
OPEN_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/t/o/$TOKEN.gif" \
  -H "User-Agent: Test-Agent/1.0")
echo "Open tracking HTTP status: $OPEN_RESPONSE"

echo ""
echo "4. Testing click tracking..."
URL_ENCODED=$(echo -n "https://example.com/clicked" | base64 | tr '+/' '-_' | tr -d '=')
CLICK_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/t/c/$TOKEN?u=$URL_ENCODED" \
  -H "User-Agent: Test-Agent/1.0")
echo "Click tracking HTTP status: $CLICK_RESPONSE"

echo ""
echo "5. Verify events in database:"
echo "Run: SELECT * FROM messageevents WHERE message_id = $MESSAGE_ID;"