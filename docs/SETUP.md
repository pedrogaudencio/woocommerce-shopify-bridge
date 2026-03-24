# Shopify WooCommerce Bridge Plugin - Setup & Testing Guide

## Overview

This plugin provides secure, one-way stock synchronization from Shopify to WooCommerce. It follows a default-deny model: only explicitly mapped products are updated from webhooks.

It also includes two admin tabs for read-only Shopify Admin API operations:
- **Credentials:** store domain, client ID, Client secret (with explicit test-connection button)
- **Export:** fetch products + inventory and download a CSV

## 1. WooCommerce Plugin Setup

1. Install and activate the plugin in WordPress.
2. Go to **WooCommerce > Settings > Integration > Shopify Sync**.
3. In **General**:
   - Configure **Global Kill Switch** as needed.
   - Configure **Shopify Webhook Secret**.
   - Optionally enable **Log Output**.
4. Save changes.

## 2. Shopify Credentials Tab

Go to **WooCommerce > Settings > Integration > Shopify Sync > Credentials** and fill:

- **Store domain:** your `*.myshopify.com` domain
- **Client ID:** from Shopify Dev Dashboard app credentials
- **Client secret:** from Shopify Dev Dashboard app credentials

Saving credentials stores them securely for admin users with WooCommerce settings access.

Use **Test Shopify connection** (separate button) to run a read-only connection check against Shopify (`GET /admin/api/<version>/shop.json`).

The plugin automatically requests a temporary Admin API access token using:

- `POST https://{store-domain}/admin/oauth/access_token`
- body: `client_id`, `client_secret`, `grant_type=client_credentials`

The generated token is cached with a creation timestamp and refreshed automatically when older than 24 hours.

If the connection fails, WooCommerce shows a specific error returned by Shopify (for example, invalid credentials, unauthorized, or domain mismatch).

Official references:
- https://help.shopify.com/en/manual/apps/app-types/custom-apps
- https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/generate-app-access-tokens-admin

## 3. Shopify Export Tab

Go to **WooCommerce > Settings > Integration > Shopify Sync > Export** and click:

**Retrieve products and inventory, then export CSV**

The export action:
- verifies permissions (`manage_woocommerce`)
- verifies nonce (CSRF protection)
- performs read-only GET requests only
- fetches products with cursor pagination
- fetches inventory levels by `inventory_item_id`
- streams a CSV download (no Shopify write operations)
- uses admin notices for success/failure feedback (instead of hard error pages)

## 4. Product Mapping Setup (Webhook Sync)

1. Go to **WooCommerce > Shopify Mappings**.
2. Click **Add New Mapping**.
3. Fill Shopify and WooCommerce fields, including `inventory_item_id` and exact WooCommerce SKU.
4. Enable sync for the mapping and save.

> The plugin enforces strict validation and blocks unsafe SKU targets.

## 5. Shopify Webhook Configuration

1. In Shopify Admin, go to **Settings > Notifications**.
2. Create webhook:
   - **Event:** Inventory level update
   - **Format:** JSON
   - **URL:** `https://your-site.com/wp-json/shopify-bridge/v1/webhook/inventory`
   - **Webhook API version:** latest stable
3. Save.
4. Copy the webhook signature secret and place it in plugin **General** settings.

## 6. Security Notes

- This plugin performs Shopify writes for none of the new tab actions.
- Credentials are only available to users with WooCommerce management capability.
- Export action is protected by nonce and capability checks.
- Webhook endpoint validates Shopify HMAC signatures.

## 7. Manual Webhook Test

Use a local payload and HMAC signature:

```bash
cat payload.json | openssl dgst -sha256 -hmac "test_secret" -binary | base64
```

```bash
curl -X POST https://your-site.com/wp-json/shopify-bridge/v1/webhook/inventory \
  -H "Content-Type: application/json" \
  -H "X-Shopify-Hmac-Sha256: YOUR_GENERATED_HMAC_STRING" \
  -d @payload.json
```

## 8. Acceptance Criteria

- [x] Webhook endpoint exists and verifies HMAC-SHA256.
- [x] Admin settings include credentials and export tabs.
- [x] Credentials can be validated via a separate read-only test-connection action.
- [x] Export action only performs GET operations and returns CSV.
- [x] Mappings remain explicit and default-deny.
- [x] Significant events are loggable through WooCommerce logs.
