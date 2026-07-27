#!/bin/bash

# Phase 3 Approval Workflow Test Script
# Tests the complete training assignment approval workflow with notifications

API_URL="http://localhost:9090/api/v1"
ORG_ID="1"

echo "=== Phase 3 Training Assignment Approval Workflow Test ==="
echo ""

# Step 1: Create a test user token
echo "Step 1: Creating test user token..."
TOKEN=$(docker exec hrms-backend php artisan tinker --execute "
\$user = \App\Models\User::first();
\$token = \$user->createToken('test-workflow')->plainTextToken;
return \$token;
" 2>/dev/null | tail -1)

echo "Token: $TOKEN"
echo ""

# Step 2: Get a training item
echo "Step 2: Getting training items..."
TRAINING_ITEMS=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/training/items?per_page=5")
TRAINING_ITEM_ID=$(echo $TRAINING_ITEMS | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)

if [ -z "$TRAINING_ITEM_ID" ]; then
  echo "No training items found. Creating test item..."
  CREATE_ITEM=$(curl -s -X POST \
    -H "Authorization: Bearer $TOKEN" \
    -H "X-Organization: $ORG_ID" \
    -H "Content-Type: application/json" \
    -d '{
      "title": "Test Training Item",
      "description": "Test item for Phase 3 workflow",
      "type": "course",
      "duration_hours": 2
    }' \
    "$API_URL/training/items")
  TRAINING_ITEM_ID=$(echo $CREATE_ITEM | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
fi

echo "Training Item ID: $TRAINING_ITEM_ID"
echo ""

# Step 3: Get employees
echo "Step 3: Getting employees..."
EMPLOYEES=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/employees?per_page=3")
EMPLOYEE_IDS=$(echo $EMPLOYEES | grep -o '"id":[0-9]*' | head -2 | cut -d':' -f2)

echo "Employee IDs: $EMPLOYEE_IDS"
echo ""

# Step 4: Create assignment (this should trigger notification)
echo "Step 4: Creating training assignments with manager approval flow..."
ASSIGNMENT=$(curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  -H "Content-Type: application/json" \
  -d "{
    \"training_item_id\": \"$TRAINING_ITEM_ID\",
    \"employee_ids\": [1, 2],
    \"due_date\": \"2026-06-30\",
    \"assignment_type\": \"mandatory\"
  }" \
  "$API_URL/training/assignments/bulk-assign")

echo "Assignment Response: $ASSIGNMENT"
echo ""

# Step 5: Check pending assignments for a manager
echo "Step 5: Checking manager's pending assignments..."
PENDING=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/training/assignments/my-pending")

echo "Pending Assignments: $PENDING"
echo ""

# Step 6: Get the first assignment ID for approval test
ASSIGNMENT_ID=$(echo $PENDING | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "First Assignment ID: $ASSIGNMENT_ID"
echo ""

# Step 7: Test notification creation
echo "Step 7: Checking created notifications..."
NOTIFICATIONS=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/notifications")

echo "Notifications: $NOTIFICATIONS"
echo ""

# Step 8: Approve the assignment (if found)
if [ -n "$ASSIGNMENT_ID" ]; then
  echo "Step 8: Approving assignment..."
  APPROVE=$(curl -s -X PATCH \
    -H "Authorization: Bearer $TOKEN" \
    -H "X-Organization: $ORG_ID" \
    -H "Content-Type: application/json" \
    -d '{
      "notes": "Approved for employee training"
    }' \
    "$API_URL/training/assignments/$ASSIGNMENT_ID/approve")

  echo "Approval Response: $APPROVE"
  echo ""
fi

# Step 9: Check updated stats
echo "Step 9: Checking assignment stats..."
STATS=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/training/assignments/stats")

echo "Assignment Stats: $STATS"
echo ""

echo "=== Test Complete ==="
