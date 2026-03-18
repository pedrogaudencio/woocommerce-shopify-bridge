# Shopify WooCommerce Bridge Plugin - Setup & Testing Guide

## Overview

This plugin provides a secure, one-way stock synchronization from Shopify to WooCommerce. It is designed with a "Default Deny" philosophy: only explicitly mapped products will have their stock updated. It listens for Shopify `inventory_levels/update` webhooks.

## 1. Setup Instructions

### WordPress / WooCommerce Configuration
1. **Install and Activate** the plugin in WordPress.
2. Go to **WooCommerce > Settings > Integration > Shopify Sync**.
3. **Global Kill Switch:** Ensure this is unchecked (disabled) to allow processing, or check it to stop all processing immediately.
4. **Log Output:** Check this if you want to see detailed logs in WooCommerce > Status > Logs.
5. **Shopify Webhook Secret:** You will paste the HMAC secret from Shopify here (see below). Save changes.

### Mapping Products
1. Go to **WooCommerce > Shopify Mappings**.
2. Click **Add New Mapping**.
3. **Shopify Product ID:** Enter the ID of the parent product in Shopify.
4. **Shopify Variant ID:** Enter the variant ID if applicable (leave blank for simple products).
5. **Shopify Inventory Item ID:** Enter the `inventory_item_id` (this is the ID sent in Shopify webhooks).
6. **WooCommerce SKU:** Enter the exact SKU of the product or variation in WooCommerce.
7. Check **Enable Sync** and click **Add Mapping**.

*Note: The plugin enforces strict validation. You cannot map an SKU belonging to a variable product parent.*

### Shopify Webhook Configuration
1. In your Shopify Admin, go to **Settings > Notifications**.
2. Click **Create webhook**.
3. **Event:** Select `Inventory level update`.
4. **Format:** JSON.
5. **URL:** Enter your site's REST API endpoint: `https://your-site.com/wp-json/shopify-bridge/v1/webhook/inventory`
6. **Webhook API version:** (Choose the latest stable).
7. Save the webhook.
8. Scroll down to the bottom of the Notifications page to find your **Webhook signature secret** (e.g., "Your webhooks will be verified with..."). Reveal and copy this secret.
9. Paste this secret into the **Shopify Webhook Secret** field in the WooCommerce settings (Step 1.5).

---

## 2. Manual Testing Guide

You can simulate Shopify webhooks using `curl` or Postman.

### Prerequisites for testing:
1. Have a WooCommerce product set to manage stock. Let's say its SKU is `TEST-SKU-123`.
2. Create a mapping in the plugin: Shopify Item ID `999888777` maps to WC SKU `TEST-SKU-123`.
3. Set your webhook secret in WooCommerce settings to `test_secret`.

### Generating a Test Payload & Signature
Shopify uses HMAC-SHA256. 

**Test Payload (`payload.json`):**
```json
{
  "inventory_item_id": 999888777,
  "location_id": 111222333,
  "available": 42
}
```

**Generate HMAC (macOS/Linux):**
```bash
cat payload.json | openssl dgst -sha256 -hmac "test_secret" -binary | base64
```
*Assume the output of this command is `YOUR_GENERATED_HMAC_STRING`.*

### Executing the Test (curl)
```bash
curl -X POST https://your-site.com/wp-json/shopify-bridge/v1/webhook/inventory \
  -H "Content-Type: application/json" \
  -H "X-Shopify-Hmac-Sha256: YOUR_GENERATED_HMAC_STRING" \
  -d @payload.json
```

### Expected Results:
1. **Success (Simple Product/Variation):** Stock for product with SKU `TEST-SKU-123` changes to `42`. Response: `{"status":"success","reason":"stock_updated","new_stock":42}`.
2. **Invalid Variable Product Target:** If the SKU mapped belongs to a variable product parent (which cannot hold stock directly), result should be HTTP 200 OK with `{"status":"error","reason":"variable_product_requires_variation"}`.
3. **Duplicate SKUs:** If the mapped SKU exists on multiple products in WooCommerce, result should be HTTP 200 OK with `{"status":"error","reason":"duplicate_wc_sku"}`.
4. **Invalid Signature:** Change `test_secret` or alter the JSON payload without recalculating the HMAC. Result should be HTTP 401 Unauthorized.
5. **Unmapped Item:** Change `inventory_item_id` in the JSON to `0000`. Result should be HTTP 200 OK with response: `{"status":"ignored","reason":"unmapped_item"}`.
6. **Global Kill Switch Enabled:** Enable the kill switch in settings and resend valid payload. Result should be HTTP 200 OK with response: `{"status":"ignored","reason":"global_sync_disabled"}`.

## 3. Acceptance Criteria (Phase 1)
- [x] Webhook endpoint exists and is secure (verifies HMAC-SHA256).
- [x] Settings page exists to configure secret and global disable.
- [x] Mappings UI exists to explicitly link Shopify Inventory Items to WC SKUs.
- [x] Enforces strict validation preventing mapping to variable product parents or handling duplicate SKUs.
- [x] Processing follows "Default Deny": Unmapped items are safely ignored (returns 200 to Shopify, logs event, does not update stock).
- [x] Valid mapped payloads successfully update absolute stock quantities in WooCommerce (including variation stock via SKU).
- [x] All significant actions (success, failure, ignored) are logged to the WC Logger if logging is enabled.