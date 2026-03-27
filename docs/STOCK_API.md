# Stock Update Feature & REST API Documentation

## Overview

The Shopify-WooCommerce Bridge now includes comprehensive stock management features with REST API endpoints for retrieving inventory data and tracking stock update history.

## Features

### 1. Stock History Tracking

All stock updates from Shopify webhooks are automatically logged to a database table (`wp_swb_stock_history`) for audit and troubleshooting purposes.

**Tracked Information:**
- Shopify inventory item ID
- WooCommerce SKU
- WooCommerce product ID
- Old stock quantity (previous value)
- New stock quantity (updated value)
- Update source (webhook, API, manual)
- Update status (success, failed)
- Error messages (if applicable)
- Timestamp

### 2. Database Schema

#### Stock History Table
```
wp_swb_stock_history (
  id                BIGINT PRIMARY KEY AUTO_INCREMENT
  shopify_item_id   VARCHAR(255) - Shopify's inventory item ID
  wc_sku            VARCHAR(255) - WooCommerce product SKU
  wc_product_id     BIGINT       - WooCommerce product ID
  old_stock         INT          - Previous stock quantity
  new_stock         INT          - Updated stock quantity
  source            VARCHAR(50)  - Update source (webhook, api, manual)
  status            VARCHAR(50)  - Update status (success, failed)
  error_message     LONGTEXT     - Error details if failed
  created_at        DATETIME     - Timestamp
)
```

## REST API Endpoints

## Security Behavior

- Permission callbacks always enforce authentication; kill switches do not open anonymous access.
- Unsigned requests are rejected with HTTP `401`.
- Kill-switch responses (`global_sync_disabled`, `stock_api_disabled`) are returned only after authentication succeeds.
- Shopify pagination next links are restricted to:
  - `https` scheme,
  - exact configured Shopify store host,
  - path prefix `/admin/api/`.
  Invalid next links are ignored and logged.

### Signed GET Authentication (External Callers)

For callers that are **not** authenticated WordPress admins, stock read endpoints require signed headers:

- `X-SWB-Timestamp`: Unix timestamp (seconds)
- `X-SWB-Signature`: lowercase hex `HMAC-SHA256`

#### Canonical payload

Build the signature payload as 4 lines joined by `\n`:

1. HTTP method (uppercase), e.g. `GET`
2. Route path (example: `/shopify-bridge/v1/stock/44611180265515/history`)
3. Sorted query string (RFC3986, no leading `?`; empty string if none)
4. Same timestamp value sent in `X-SWB-Timestamp`

Then compute:

- `signature = hex(HMAC_SHA256(canonical_payload, webhook_secret))`

#### Freshness and replay protections

- Requests outside the freshness window (10 minutes) are rejected.
- Reuse of the same signed request is treated as replay and rejected.

#### Copyable example (history endpoint)

```bash
SECRET='your_webhook_secret_here'
BASE='https://your-site.com'
ITEM_ID='44611180265515'
ROUTE="/shopify-bridge/v1/stock/${ITEM_ID}/history"
QUERY='limit=50'
TS="$(date +%s)"

CANONICAL=$(printf 'GET\n%s\n%s\n%s' "$ROUTE" "$QUERY" "$TS")
SIG=$(printf '%s' "$CANONICAL" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.* //')

curl -sS "${BASE}/wp-json${ROUTE}?${QUERY}" \
  -H "X-SWB-Timestamp: ${TS}" \
  -H "X-SWB-Signature: ${SIG}" \
  -H "Accept: application/json"
```

#### Python helper

```bash
python3 - <<'PY'
import hmac, hashlib, time, urllib.parse

secret = b"your_webhook_secret_here"
route = "/shopify-bridge/v1/stock/44611180265515/history"
params = {"limit": "50"}
query = urllib.parse.urlencode(sorted(params.items()), doseq=True, safe="", quote_via=urllib.parse.quote)
ts = str(int(time.time()))
canonical = "\n".join(["GET", route, query, ts])
sig = hmac.new(secret, canonical.encode(), hashlib.sha256).hexdigest()

print("Timestamp:", ts)
print("Signature:", sig)
print("Canonical:\n" + canonical)
PY
```

### General Tab Kill Switches

- `Global Kill Switch` stops incoming webhook stock processing.
- `Stock REST API Kill Switch` disables these REST API read endpoints:
  - `GET /stock/{inventory_item_id}`
  - `GET /stock/{inventory_item_id}/history`

When the Stock REST API kill switch is enabled, those endpoints return HTTP `503` with:

```json
{
  "success": false,
  "error": "stock_api_disabled",
  "message": "Stock REST API is currently disabled."
}
```

### Base URL
```
/wp-json/shopify-bridge/v1
```

### 1. Get Inventory Stock

Fetch current stock levels from Shopify for a mapped inventory item.

**Endpoint:**
```
GET /stock/{inventory_item_id}
```

**Parameters:**
- `inventory_item_id` (string, required) - The Shopify inventory item ID

**Authentication:**
- WordPress admin users (`manage_woocommerce`) OR signed GET headers (`X-SWB-Timestamp`, `X-SWB-Signature`)

Unauthenticated requests receive `401` even when kill switches are enabled.

**Response (Success):**
```json
{
  "success": true,
  "inventory_item_id": "12345678",
  "wc_sku": "PRODUCT-SKU",
  "shopify": {
    "inventory_item_id": "12345678",
    "locations": [
      {
        "location_id": "123456",
        "available": 50
      },
      {
        "location_id": "123457",
        "available": 30
      }
    ]
  },
  "woocommerce": {
    "sku": "PRODUCT-SKU",
    "stock": 50
  },
  "mapping": {
    "id": 1,
    "enabled": true,
    "shopify_item_id": "12345678",
    "wc_sku": "PRODUCT-SKU"
  }
}
```

**Response (Error):**
```json
{
  "success": false,
  "error": "unmapped_item",
  "message": "This inventory item is not mapped in the system."
}
```

**HTTP Status Codes:**
- `200` - Success
- `401` - Missing/invalid signature for unauthenticated requests
- `404` - Item not mapped
- `403` - Mapping disabled
- `503` - Stock REST API kill switch enabled
- `503` - Global sync disabled
- `500` - Shopify API error

**Example Request:**
```bash
curl -X GET "https://example.com/wp-json/shopify-bridge/v1/stock/12345678" \
  -H "Authorization: Bearer YOUR_WP_USER_TOKEN"
```

### 2. Get Stock History

Retrieve the update history for a specific inventory item.

**Endpoint:**
```
GET /stock/{inventory_item_id}/history
```

**Parameters:**
- `inventory_item_id` (string, required) - The Shopify inventory item ID
- `limit` (integer, optional, default: 50) - Number of records to retrieve (1-200)

**Authentication:**
- WordPress admin users (`manage_woocommerce`) OR signed GET headers (`X-SWB-Timestamp`, `X-SWB-Signature`)

**Response (Success):**
```json
{
  "success": true,
  "inventory_item_id": "12345678",
  "wc_sku": "PRODUCT-SKU",
  "limit": 50,
  "count": 2,
  "history": [
    {
      "id": 1,
      "shopify_item_id": "12345678",
      "wc_sku": "PRODUCT-SKU",
      "wc_product_id": 123,
      "old_stock": 50,
      "new_stock": 45,
      "source": "webhook",
      "status": "success",
      "error_message": null,
      "created_at": "2026-03-24 10:30:00"
    },
    {
      "id": 2,
      "shopify_item_id": "12345678",
      "wc_sku": "PRODUCT-SKU",
      "wc_product_id": 123,
      "old_stock": 45,
      "new_stock": 40,
      "source": "webhook",
      "status": "success",
      "error_message": null,
      "created_at": "2026-03-24 11:15:00"
    }
  ],
  "mapping": {
    "id": 1,
    "enabled": true
  }
}
```

**HTTP Status Codes:**
- `200` - Success
- `401` - Missing/invalid signature for unauthenticated requests
- `404` - Item not mapped
- `403` - Mapping disabled
- `503` - Stock REST API kill switch enabled
- `503` - Global sync disabled

**Example Request:**
```bash
curl -X GET "https://example.com/wp-json/shopify-bridge/v1/stock/12345678/history?limit=100" \
  -H "Authorization: Bearer YOUR_WP_USER_TOKEN"
```

### 3. Webhook Endpoint (Existing)

Receive and process stock updates from Shopify webhooks.

**Endpoint:**
```
POST /webhook/inventory
```

**Expected Payload:**
```json
{
  "inventory_item_id": "12345678",
  "available": 50
}
```

**Headers Required:**
```
X-Shopify-Hmac-Sha256: [Shopify HMAC signature]
```

**Processing:**
1. Verifies HMAC signature
2. Checks if item is mapped and enabled
3. Finds WooCommerce product by SKU
4. Validates product type (not parent variable)
5. Updates stock in WooCommerce
6. Logs update to history table
7. Fires WooCommerce hooks

## Usage Examples

### Node.js/JavaScript

```javascript
// Fetch current stock
const getStock = async (inventoryItemId) => {
  const response = await fetch(
    `/wp-json/shopify-bridge/v1/stock/${inventoryItemId}`,
    {
      headers: {
        'Authorization': 'Bearer ' + userToken
      }
    }
  );
  return response.json();
};

// Get stock history
const getHistory = async (inventoryItemId, limit = 50) => {
  const response = await fetch(
    `/wp-json/shopify-bridge/v1/stock/${inventoryItemId}/history?limit=${limit}`,
    {
      headers: {
        'Authorization': 'Bearer ' + userToken
      }
    }
  );
  return response.json();
};

// Usage
const stock = await getStock('12345678');
const history = await getHistory('12345678', 100);
```

### PHP (WordPress)

```php
// Using WordPress REST API client
$stock = wp_remote_get(
  home_url() . '/wp-json/shopify-bridge/v1/stock/12345678',
  array(
    'headers' => array(
      'Authorization' => 'Bearer ' . wp_create_nonce('wp_rest')
    )
  )
);

$data = json_decode(wp_remote_retrieve_body($stock), true);

if ($data['success']) {
  echo 'Shopify Stock: ' . $data['shopify']['locations'][0]['available'];
  echo 'WooCommerce Stock: ' . $data['woocommerce']['stock'];
}
```

### cURL

```bash
# Get current stock
curl -X GET "https://example.com/wp-json/shopify-bridge/v1/stock/12345678" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Get history with custom limit
curl -X GET "https://example.com/wp-json/shopify-bridge/v1/stock/12345678/history?limit=200" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Error Codes

### Common Error Responses

| Error Code | HTTP Status | Description |
|-----------|------------|-------------|
| `stock_api_disabled` | 503 | Stock REST API is disabled in plugin settings |
| `global_sync_disabled` | 503 | Global sync is disabled in plugin settings |
| `unmapped_item` | 404 | Inventory item is not mapped in the system |
| `mapping_disabled` | 403 | The mapping for this item is disabled |
| `shopify_api_error` | 500 | Error communicating with Shopify API |
| `wc_sku_not_found` | 404 | WooCommerce SKU doesn't exist |
| `duplicate_wc_sku` | 400 | Multiple WooCommerce products have the same SKU |
| `invalid_payload` | 400 | Webhook payload is missing required fields |
| `variable_product_requires_variation` | 400 | Parent variable products can't be updated directly |
| `product_not_managing_stock` | 400 | Product has stock management disabled |
| `stock_update_failed` | 500 | Stock update operation failed |

## Permission Model

### API Access

**For GET endpoints** (stock retrieval):
- ✅ WordPress users with `manage_woocommerce` capability
- ✅ Requests with valid Shopify HMAC signature
- ❌ Unauthenticated requests

**For POST endpoints** (webhooks):
- ✅ Requests with valid Shopify HMAC signature
- ❌ WordPress user authentication

### Database Access

All database access is logged through the `SWB_Logger` class for security auditing.

## Database Methods

### SWB_DB Class

```php
// Log a stock update
SWB_DB::log_stock_update(array(
  'shopify_item_id' => '12345678',
  'wc_sku'          => 'PRODUCT-SKU',
  'wc_product_id'   => 123,
  'old_stock'       => 50,
  'new_stock'       => 45,
  'source'          => 'webhook',
  'status'          => 'success',
  'error_message'   => null
));

// Get stock history
$history = SWB_DB::get_stock_history('12345678', $limit = 50);

// Get latest stock from history
$stock = SWB_DB::get_current_stock('12345678');

// Get mapping by Shopify ID
$mapping = SWB_DB::get_mapping_by_shopify_id('12345678');
```

## Shopify API Client

### New Methods

```php
// Fetch inventory level for a single item
$client = new SWB_Shopify_API_Client();
$response = $client->get_inventory_level_for_item('12345678');

// Returns:
// array(
//   'inventory_item_id' => '12345678',
//   'locations' => array(
//     array(
//       'location_id' => '123456',
//       'available' => 50
//     )
//   )
// )

// Fetch inventory for multiple items
$levels = $client->get_inventory_levels_for_item_ids([
  '12345678',
  '87654321'
]);
```

## Logging

All operations are logged using the `SWB_Logger` class:

```php
SWB_Logger::info('Stock updated successfully.', array(
  'shopify_item_id' => '12345678',
  'wc_sku' => 'PRODUCT-SKU',
  'old_stock' => 50,
  'new_stock' => 45
));

SWB_Logger::error('Stock update failed.', array(
  'error' => 'Product not managing stock'
));
```

## Best Practices

1. **Always verify mapped items exist** before requesting stock data
2. **Handle rate limits** - Implement exponential backoff for API calls
3. **Cache responses** when making frequent requests
4. **Monitor the history table** for failed updates
5. **Keep stock sync enabled** in plugin settings for production
6. **Use webhooks** for real-time updates rather than polling
7. **Test with a staging site** before enabling in production

## Troubleshooting

### Issue: "Item not mapped"
- Check if the inventory item ID is correct
- Verify the mapping exists in the Mappings admin page
- Ensure the mapping is enabled

### Issue: "Global sync disabled"
- Go to WooCommerce → Shopify Bridge settings
- Ensure the "Global Kill Switch" option is unchecked

### Issue: "Stock REST API is currently disabled"
- Go to WooCommerce → Shopify Bridge settings → General
- Ensure the "Stock REST API Kill Switch" option is unchecked

### Issue: "Stock update failed"
- Check the logs for specific error messages
- Verify the WooCommerce product has stock management enabled
- Ensure the product SKU is unique (no duplicates)

### Issue: API returns 401 Unauthorized
- For WordPress users: Verify authentication token is valid
- For Shopify webhooks: Verify webhook secret is configured

## Changelog

### Version 1.0.0
- Initial release
- Webhook-based stock updates
- REST API for stock retrieval
- Stock history tracking
- Shopify inventory level queries

