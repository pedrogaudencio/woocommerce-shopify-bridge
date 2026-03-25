# Test Cases for Stock Update Feature

This file contains comprehensive test cases for the stock update feature and REST API endpoints.

## REST API URL Formats

The REST API can be accessed via two different URL formats:

**Standard format (preferred):**
```
http://localhost:8000/wp-json/shopify-bridge/v1/...
```

**Alternative format (if standard doesn't work locally):**
```
http://localhost:8000/index.php?rest_route=/shopify-bridge/v1/...
```

If you encounter 404 errors or the `/wp-json/` path is not accessible in your local environment, use the alternative `?rest_route=` format. Both formats are equivalent and return the same results.

## Prerequisites

1. Plugin is activated and configured with Shopify credentials
2. Global sync is enabled in plugin settings
3. At least one product mapping exists
4. WooCommerce product exists with the mapped SKU
5. WordPress user has `manage_woocommerce` capability or valid REST API token

## Test Cases

### TC-1: Get Stock for Unmapped Item

**Objective:** Verify API returns 404 for unmapped inventory items

**Steps:**
```bash
curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/UNMAPPED_ID" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

**Expected Result:**
- HTTP Status: 404
- Response:
```json
{
  "success": false,
  "error": "unmapped_item",
  "message": "This inventory item is not mapped in the system."
}
```

### TC-2: Get Stock for Mapped Item

**Objective:** Verify successful retrieval of stock for mapped item

**Setup:**
- Create mapping: Shopify Item ID = `123456` → WC SKU = `TEST-SKU`
- Create WooCommerce product with SKU `TEST-SKU`
- Add stock quantity: 50

**Steps:**
```bash
curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

**Expected Result:**
- HTTP Status: 200
- Response includes both Shopify and WooCommerce stock
- `woocommerce.stock` = 50

### TC-3: Get Stock History - Empty History

**Objective:** Verify history endpoint returns empty array for new items

**Setup:**
- Create new mapping with no previous updates
- Create mapped WooCommerce product

**Steps:**
```bash
curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456/history" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected Result:**
- HTTP Status: 200
- `count` = 0
- `history` = []

### TC-4: Get Stock History with Custom Limit

**Objective:** Verify history limit parameter works correctly

**Setup:**
- Create 10 stock update records for an item

**Steps:**
```bash
curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456/history?limit=5" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected Result:**
- HTTP Status: 200
- `count` = 5
- Returns most recent 5 records in descending date order

### TC-5: Webhook Stock Update - Success

**Objective:** Verify webhook correctly updates stock and logs to history

**Setup:**
- Shopify Item ID `123456` mapped to WC SKU `TEST-SKU`
- Current WC stock: 50
- Shopify webhook secret configured

**Steps:**
```bash
# Generate HMAC signature
SECRET="your_webhook_secret"
PAYLOAD='{"inventory_item_id":"123456","available":45}'
HMAC=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)

curl -X POST "http://localhost:8000/wp-json/shopify-bridge/v1/webhook/inventory" \
  -H "X-Shopify-Hmac-Sha256: $HMAC" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Expected Result:**
- HTTP Status: 200
- Response: `{"status":"success","reason":"stock_updated","new_stock":45}`
- WC product stock: 45
- History entry created with old_stock=50, new_stock=45

### TC-6: Webhook Stock Update - Duplicate SKU Error

**Objective:** Verify webhook rejects when multiple products share same SKU

**Setup:**
- Create two products with SKU `DUPLICATE-SKU`
- Map item to `DUPLICATE-SKU`

**Steps:**
```bash
PAYLOAD='{"inventory_item_id":"123456","available":30}'
HMAC=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)

curl -X POST "http://localhost:8000/wp-json/shopify-bridge/v1/webhook/inventory" \
  -H "X-Shopify-Hmac-Sha256: $HMAC" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Expected Result:**
- HTTP Status: 200
- Response: `{"status":"error","reason":"duplicate_wc_sku"}`
- No stock update occurs
- Error logged

### TC-7: Webhook Stock Update - Variable Product Error

**Objective:** Verify webhook rejects parent variable products

**Setup:**
- Create variable product with SKU `VARIABLE-PARENT`
- Create variation with SKU `VARIABLE-CHILD`
- Map to `VARIABLE-PARENT` (incorrect)

**Steps:**
```bash
PAYLOAD='{"inventory_item_id":"123456","available":30}'
HMAC=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)

curl -X POST "http://localhost:8000/wp-json/shopify-bridge/v1/webhook/inventory" \
  -H "X-Shopify-Hmac-Sha256: $HMAC" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Expected Result:**
- HTTP Status: 200
- Response: `{"status":"error","reason":"variable_product_requires_variation"}`
- No stock update
- Error logged

### TC-8: Webhook Stock Update - No Stock Management

**Objective:** Verify webhook ignores products without stock management

**Setup:**
- Create product with stock management disabled
- Map to its SKU

**Steps:**
```bash
PAYLOAD='{"inventory_item_id":"123456","available":30}'
HMAC=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)

curl -X POST "http://localhost:8000/wp-json/shopify-bridge/v1/webhook/inventory" \
  -H "X-Shopify-Hmac-Sha256: $HMAC" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Expected Result:**
- HTTP Status: 200
- Response: `{"status":"ignored","reason":"product_not_managing_stock"}`
- No error, just logged as ignored

### TC-9: Webhook Invalid HMAC Signature

**Objective:** Verify webhook rejects invalid signatures

**Steps:**
```bash
PAYLOAD='{"inventory_item_id":"123456","available":45}'

curl -X POST "http://localhost:8000/wp-json/shopify-bridge/v1/webhook/inventory" \
  -H "X-Shopify-Hmac-Sha256: invalid_signature" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Expected Result:**
- HTTP Status: 401
- Response: `{"code":"swb_invalid_signature","message":"Invalid signature."}`

### TC-10: API Request Without Authentication

**Objective:** Verify unauthenticated requests are rejected

**Steps:**
```bash
curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456"
```

**Expected Result:**
- HTTP Status: 401 or 403
- Unauthorized error response

### TC-11: Global Sync Disabled

**Objective:** Verify endpoints return 503 when sync is disabled

**Setup:**
- Disable "Enable Shopify Bridge" in settings

**Steps:**
```bash
curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected Result:**
- HTTP Status: 503
- Response: `{"success":false,"error":"global_sync_disabled"}`

### TC-12: Disabled Mapping

**Objective:** Verify endpoints reject disabled mappings

**Setup:**
- Create mapping
- Disable the mapping

**Steps:**
```bash
curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected Result:**
- HTTP Status: 403
- Response: `{"success":false,"error":"mapping_disabled"}`

### TC-13: Stock History Audit Trail

**Objective:** Verify complete audit trail is maintained

**Setup:**
- Send 3 webhook updates with different quantities
- Space them out with timestamps

**Steps:**
```bash
# Update 1: 50 → 45
# Update 2: 45 → 40
# Update 3: 40 → 35

curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456/history?limit=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected Result:**
- HTTP Status: 200
- `count` = 3
- History shows all 3 updates in reverse chronological order
- Each entry has correct old_stock/new_stock values
- Timestamps are accurate

### TC-14: Webhook Stock Unchanged

**Objective:** Verify webhook handles unchanged stock correctly

**Setup:**
- Current WC stock: 50
- Shopify stock: 50

**Steps:**
```bash
PAYLOAD='{"inventory_item_id":"123456","available":50}'
HMAC=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)

curl -X POST "http://localhost:8000/wp-json/shopify-bridge/v1/webhook/inventory" \
  -H "X-Shopify-Hmac-Sha256: $HMAC" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Expected Result:**
- HTTP Status: 200
- Response: `{"status":"success","reason":"stock_unchanged","new_stock":50}`
- No database update (optimization)

### TC-15: Concurrent Stock Updates

**Objective:** Verify system handles concurrent updates correctly

**Setup:**
- Create mapping
- Send multiple webhook updates simultaneously

**Steps:**
```bash
# Send 5 concurrent requests with different quantities
for i in {45,40,35,30,25}; do
  PAYLOAD="{\"inventory_item_id\":\"123456\",\"available\":$i}"
  HMAC=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)
  curl -X POST "http://localhost:8000/wp-json/shopify-bridge/v1/webhook/inventory" \
    -H "X-Shopify-Hmac-Sha256: $HMAC" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD" &
done
wait
```

**Expected Result:**
- All requests succeed
- Final stock reflects last update
- All updates logged in history
- No data corruption

## Database Query Tests

### DB-1: Verify Stock History Table Structure

```sql
DESCRIBE wp_swb_stock_history;
```

**Expected:**
- id (BIGINT, PRIMARY KEY)
- shopify_item_id (VARCHAR 255)
- wc_sku (VARCHAR 255)
- wc_product_id (BIGINT, nullable)
- old_stock (INT, nullable)
- new_stock (INT)
- source (VARCHAR 50)
- status (VARCHAR 50)
- error_message (LONGTEXT)
- created_at (DATETIME)

### DB-2: Verify Indexes

```sql
SHOW INDEX FROM wp_swb_stock_history;
```

**Expected:**
- shopify_item_id index exists
- wc_sku index exists
- created_at index exists

### DB-3: Query Recent Updates

```sql
SELECT * FROM wp_swb_stock_history 
WHERE shopify_item_id = '123456' 
ORDER BY created_at DESC 
LIMIT 10;
```

**Expected:**
- Returns records in reverse chronological order
- All relevant fields populated

## Performance Tests

### PERF-1: API Response Time - Under 200ms

```bash
time curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### PERF-2: History Query Performance - 50 Records

```bash
time curl -X GET "http://localhost:8000/wp-json/shopify-bridge/v1/stock/123456/history?limit=50" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### PERF-3: Webhook Processing Time - Under 1 second

**Expected:** Webhook processes and returns within 1 second

## Logging Tests

### LOG-1: Verify Update Logged

```bash
# Check logs for successful update
tail -f /var/log/wordpress/shopify-bridge.log
```

**Expected:**
```
[INFO] Stock updated successfully. {shopify_item_id: "123456", ...}
```

### LOG-2: Verify Error Logged

```bash
# Check logs for error
tail -f /var/log/wordpress/shopify-bridge.log
```

**Expected:**
```
[ERROR] Stock update failed. {error: "..."}
```

## Security Tests

### SEC-1: HMAC Validation

- Test with invalid secret ✓
- Test with modified payload ✓
- Test with missing signature ✓
- Test with valid signature ✓

### SEC-2: Permission Checks

- Test without authentication ✓
- Test with non-admin user ✓
- Test with admin user ✓
- Test with valid webhook signature ✓

### SEC-3: Input Sanitization

- Test with SQL injection in inventory_item_id
- Test with XSS payloads
- Test with very long strings
- Test with special characters

## Test Automation

Use the following script to automate testing:

```bash
#!/bin/bash

# Use standard format or alternative if 404 errors occur
BASE_URL="http://localhost:8000/wp-json/shopify-bridge/v1"
# Alternative URL (uncomment if /wp-json/ doesn't work):
# BASE_URL="http://localhost:8000/index.php?rest_route=/shopify-bridge/v1"

TOKEN="your_token"
SECRET="your_webhook_secret"
ITEM_ID="123456"

# Test 1: Get Stock
echo "Test 1: Get Stock"
curl -s -X GET "$BASE_URL/stock/$ITEM_ID" \
  -H "Authorization: Bearer $TOKEN" | jq .

# Test 2: Get History
echo "Test 2: Get History"
curl -s -X GET "$BASE_URL/stock/$ITEM_ID/history" \
  -H "Authorization: Bearer $TOKEN" | jq .

# Test 3: Webhook Update
echo "Test 3: Webhook Update"
PAYLOAD='{"inventory_item_id":"'$ITEM_ID'","available":45}'
HMAC=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -binary | base64)
curl -s -X POST "$BASE_URL/webhook/inventory" \
  -H "X-Shopify-Hmac-Sha256: $HMAC" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD" | jq .
```

Save as `test-api.sh` and run:
```bash
chmod +x test-api.sh
./test-api.sh
```

