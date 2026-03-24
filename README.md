# Shopify WooCommerce Bridge Plugin

**Version:** 1.0.0 (Phase 1 + admin export tools)
**Requires:** WordPress and WooCommerce

## Overview

The Shopify WooCommerce Bridge is a custom WordPress plugin designed for secure, one-way stock synchronization from Shopify to WooCommerce.

Phase 1 focuses on webhook-driven inventory updates, and now also includes secure, read-only Shopify Admin API tooling in WooCommerce settings to validate credentials and export product + inventory data to CSV.

## Core Features

- **Default Deny Architecture:** Products are ignored by default. Stock updates only occur for items that are explicitly mapped in the WooCommerce admin.
- **Secure Webhooks:** Validates all incoming payloads using Shopify's standard HMAC-SHA256 signature verification.
- **Explicit Mappings:** Custom admin interface to link Shopify Product and Variant IDs directly to WooCommerce Product and Variation IDs.
- **Credentials Tab + Connection Test:** Stores Shopify store domain, client ID, and client secret, then runs a read-only connection check when saving.
- **Read-Only Export Tab:** One-click action to fetch Shopify products and inventory levels and download a CSV export.
- **Safety Controls:** Includes a global kill switch to instantly disable sync without deleting settings or mappings.
- **Diagnostics:** Integrates with the native WooCommerce Logger (`WC_Logger`) for clear operational visibility.

## Setup & Testing

For complete instructions on configuring WordPress, setting up Shopify webhooks, app credentials, product mappings, and manual tests, please see the Setup Guide:

[**View Setup & Testing Guide**](docs/SETUP.md)

## Future Phases (Not implemented)

- Order forwarding
- Price and image synchronization
- Bi-directional syncing
- Automatic product creation
