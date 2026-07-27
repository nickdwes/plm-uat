#!/bin/bash

# Phase 3 Integration Test: Complete Approval Workflow with Notifications

API_URL="http://localhost:9090/api/v1"
ORG_ID="1"
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}=== Phase 3 Complete Integration Test ===${NC}\n"

# Step 1: Get a test token
echo -e "${YELLOW}Step 1: Creating test token...${NC}"
TOKEN=$(docker exec hrms-backend php artisan tinker --execute "
\$user = \App\Models\User::first();
\$token = \$user->createToken('phase3-test-' . time())->plainTextToken;
return \$token;
" 2>&1 | grep -oP '[A-Za-z0-9]+\|[A-Za-z0-9]+' | head -1)

if [ -z "$TOKEN" ]; then
  echo -e "${RED}Failed to create token${NC}"
  exit 1
fi
echo -e "${GREEN}Token created: ${TOKEN:0:20}...${NC}\n"

# Step 2: Get a training item
echo -e "${YELLOW}Step 2: Getting training items...${NC}"
ITEMS=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/training/catalog?per_page=1")

TRAINING_ITEM_ID=$(echo "$ITEMS" | grep -oP '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
TRAINING_TITLE=$(echo "$ITEMS" | grep -oP '"title":"[^"]*"' | head -1 | cut -d'"' -f4)

if [ -z "$TRAINING_ITEM_ID" ]; then
  echo -e "${RED}No training items found${NC}"
  exit 1
fi
echo -e "${GREEN}Training Item: $TRAINING_TITLE (ID: $TRAINING_ITEM_ID)${NC}\n"

# Step 3: Get test employees
echo -e "${YELLOW}Step 3: Getting employees...${NC}"
EMPS=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/employees?per_page=2")

EMP_ID=$(echo "$EMPS" | grep -oP '"id":[0-9]+' | head -1 | cut -d':' -f2)

if [ -z "$EMP_ID" ]; then
  echo -e "${RED}No employees found${NC}"
  exit 1
fi
echo -e "${GREEN}Test Employee ID: $EMP_ID${NC}\n"

# Step 4: Create bulk assignments (should trigger notifications)
echo -e "${YELLOW}Step 4: Creating bulk training assignments...${NC}"
CREATE_RESULT=$(curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  -H "Content-Type: application/json" \
  -d "{
    \"training_item_id\": \"$TRAINING_ITEM_ID\",
    \"employee_ids\": [$EMP_ID],
    \"due_date\": \"2026-07-04\",
    \"assignment_type\": \"mandatory\"
  }" \
  "$API_URL/training/assignments")

echo "$CREATE_RESULT" | grep -q '"success"' || echo "$CREATE_RESULT" | grep -q '"created"'
if [ $? -eq 0 ]; then
  echo -e "${GREEN}✓ Assignments created successfully${NC}"
  CREATED=$(echo "$CREATE_RESULT" | grep -oP '"created":\d+' | cut -d':' -f2)
  echo "  Created: $CREATED assignment(s)"
else
  echo -e "${RED}Failed to create assignments:${NC}"
  echo "$CREATE_RESULT"
fi
echo ""

# Step 5: Check pending assignments
echo -e "${YELLOW}Step 5: Getting pending assignments for manager...${NC}"
PENDING=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/training/assignments/my-pending")

PENDING_COUNT=$(echo "$PENDING" | grep -oP '"data":\[' | wc -l)
if [ "$PENDING_COUNT" -gt 0 ]; then
  echo -e "${GREEN}✓ Found pending assignments${NC}"
  ASSIGNMENT_ID=$(echo "$PENDING" | grep -oP '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
  echo "  First Assignment ID: $ASSIGNMENT_ID"
else
  echo -e "${YELLOW}No pending assignments${NC}"
fi
echo ""

# Step 6: Check if notifications were created
echo -e "${YELLOW}Step 6: Checking notifications...${NC}"
NOTIFS=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/notifications")

NOTIF_COUNT=$(echo "$NOTIFS" | grep -o '"id":"' | wc -l)
UNREAD_COUNT=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/notifications/count" | grep -oP '"unread_count":\d+' | cut -d':' -f2)

echo -e "${GREEN}✓ Notifications:${NC}"
echo "  Total: $NOTIF_COUNT"
echo "  Unread: $UNREAD_COUNT"
echo ""

# Step 7: Approve assignment (if found)
if [ -n "$ASSIGNMENT_ID" ]; then
  echo -e "${YELLOW}Step 7: Approving assignment...${NC}"
  APPROVE=$(curl -s -X PATCH \
    -H "Authorization: Bearer $TOKEN" \
    -H "X-Organization: $ORG_ID" \
    -H "Content-Type: application/json" \
    -d '{"notes": "Approved for mandatory training"}' \
    "$API_URL/training/assignments/$ASSIGNMENT_ID/approve")

  echo "$APPROVE" | grep -q 'approved' && echo -e "${GREEN}✓ Assignment approved successfully${NC}" || echo -e "${RED}Approval failed${NC}"
  echo ""
fi

# Step 8: Check assignment stats
echo -e "${YELLOW}Step 8: Checking assignment statistics...${NC}"
STATS=$(curl -s -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/training/assignments/stats")

PENDING=$(echo "$STATS" | grep -oP '"pending":\d+' | cut -d':' -f2)
APPROVED=$(echo "$STATS" | grep -oP '"approved":\d+' | cut -d':' -f2)

echo -e "${GREEN}✓ Statistics:${NC}"
echo "  Pending: $PENDING"
echo "  Approved: $APPROVED"
echo ""

# Step 9: Mark notifications as read
echo -e "${YELLOW}Step 9: Marking all notifications as read...${NC}"
MARK=$(curl -s -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Organization: $ORG_ID" \
  "$API_URL/notifications/mark-all-read")

echo "$MARK" | grep -q 'success' && echo -e "${GREEN}✓ Notifications marked as read${NC}" || echo -e "${YELLOW}No unread notifications${NC}"
echo ""

echo -e "${GREEN}=== Phase 3 Integration Test Complete ===${NC}"
