# Shopify WooCommerce Bridge Plugin

**Version:** 1.0.0 (Phase 1)
**Requires:** WordPress and WooCommerce

## Overview

The Shopify WooCommerce Bridge is a custom WordPress plugin designed for secure, one-way stock synchronization from Shopify to WooCommerce. 

Phase 1 focuses strictly on webhook-driven inventory updates. It listens for `inventory_levels/update` webhooks from Shopify and updates the absolute stock quantities in WooCommerce.

## Core Features

- **Default Deny Architecture:** Products are ignored by default. Stock updates only occur for items that are explicitly mapped in the WooCommerce admin.
- **Secure Webhooks:** Validates all incoming payloads using Shopify's standard HMAC-SHA256 signature verification.
- **Explicit Mappings:** Custom admin interface to link Shopify Inventory Item IDs directly to WooCommerce Product or Variation IDs.
- **Safety Controls:** Includes a global kill switch to instantly disable sync without deleting settings or mappings.
- **Diagnostics:** Integrates with the native WooCommerce Logger (`WC_Logger`) for clear operational visibility.

## Setup & Testing

For complete instructions on configuring WordPress, setting up Shopify Webhooks, creating product mappings, and performing manual tests, please see the Setup Guide:

[**View Setup & Testing Guide**](docs/SETUP.md)

## Future Phases (Not implemented)
*   Order forwarding
*   Price and image synchronization
*   Bi-directional syncing
*   Automatic product creation