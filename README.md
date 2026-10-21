# OdooWoo Portal

The OdooWoo customer app for WordPress. Customers with an OdooWoo license manage their dashboards, WooCommerce and Odoo connections, mappings, gift cards, manual syncs and sync logs from pages on your own site. It is built on [Beltoft Licenses](https://github.com/beltoftandersen/beltoft-licenses) and Beltoft Services, and it talks to the OdooWoo service through the signed connection that Beltoft Services stores.

Author: beltoft.net. License: GPL-2.0-or-later. Requires WordPress 6.5, PHP 8.1, WooCommerce, Beltoft Licenses 0.7.0 or newer and Beltoft Services 0.1.1 or newer. Without them (or with older ones) the plugin shows an admin notice and does nothing.

## How it works

- The browser never talks to OdooWoo and never receives a secret. Every request goes to this plugin's REST routes (`odoowoo-portal/v1`), which need a logged-in user and the WordPress REST nonce, name a license, and check that the user owns it before the plugin calls the service.
- The license is the entitlement and the Beltoft Services resource holds the tenant. Nothing is stored twice: no second secret, no second signer.
- The app is read-only while the license is not active, the service is not ready, or the viewer is a support user ("View as support" for shop managers).
- Cards are small vanilla JavaScript files, loaded only on pages that contain that card. No build step.
- My Account → Licenses gets an "Open OdooWoo" button on each of the user's OdooWoo licenses.

## Setup

1. Install and activate the plugin and its dependencies.
2. In Beltoft Services, add an HTTP connection to the OdooWoo service (base URL, HMAC secret).
3. Go to Settings → OdooWoo and choose the connection, the license software slug (default `odoowoo`), the list page and the dashboard page.
4. Put the cards on those two pages, either with the Bricks elements (category `odoowoo`) or the shortcode `[odoowoo_card name="dashboard-list"]`.

Card names: `dashboard-list`, `dashboard-header`, `woo-connection`, `woo-settings`, `odoo-connection`, `odoo-settings`, `payment-mappings`, `tax-mappings`, `price-mappings`, `stock-mappings`, `order-route-mappings`, `order-pricelist-mappings`, `gift-cards`, `manual-sync`, `sync-logs`.

## Bricks

When Bricks is active the plugin registers fifteen elements (one per card), five dynamic data tags (`{odoowoo_dashboard_name}`, `{odoowoo_dashboard_state}`, `{odoowoo_plan_dashboards}`, `{odoowoo_plan_used}`, `{odoowoo_has_plan}`) and an element condition, "Has an OdooWoo plan".

## Hooks

- Filters: `odoowoo_portal_dependencies`, `odoowoo_portal_cards`, `odoowoo_portal_rate_limit( $limit, $bucket )`.
- Actions: `odoowoo_portal_log( $line )`, `odoowoo_portal_dashboard_deleted`.

## Development

```bash
/root/plugin-tests/run.sh /path/to/odoowoo-portal      # PHP suite
node --test tests/js                                   # JavaScript logic
wp i18n make-pot . languages/odoowoo-portal.pot --exclude=tests,.internal
```

The service is always faked in tests; no test makes a network call.
